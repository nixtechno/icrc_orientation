<?php

namespace Tests\Feature;

use App\Models\{Activity, DailyAttendance, Programme, ProgrammeDay, Submission, User};
use App\Services\{AttendanceTrend, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class PortalEnhancementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        config(['services.recaptcha.enabled' => false]);
    }

    private function account(string $role = 'staff', bool $active = true): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => $active]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    public function test_portal_pages_show_abuja_nigeria_instead_of_the_timezone_name(): void
    {
        $this->assertSame('Abuja, Nigeria', Programme::first()->timezoneLabel());
        foreach (['staff' => ['/dashboard', '/timetable', '/attendance'], 'admin' => ['/dashboard', '/timetable', '/attendance', '/attendance/security/audit']] as $role => $pages) {
            $this->actingAs($this->account($role));
            foreach ($pages as $page) {
                $this->get($page)->assertOk()->assertSee('Abuja, Nigeria')->assertDontSee('Africa/Lagos');
            }
        }
    }

    public function test_timetable_groups_sessions_into_week_tabs(): void
    {
        $this->actingAs($this->account('admin'));
        $this->travelTo(\Carbon\Carbon::parse('2026-11-17 09:00', 'Africa/Lagos'));
        $html = $this->get('/timetable')->assertOk()->assertSee('role="tablist"', false)->assertSee('timetable-tabs.js', false)->getContent();
        $dom = new \DOMDocument; @$dom->loadHTML($html); $xpath = new \DOMXPath($dom);
        $this->assertSame(6, $xpath->query('//*[@role="tab"]')->length);
        $this->assertSame(6, $xpath->query('//*[@role="tabpanel"]')->length);
        // The current week opens by default; the other weeks are hidden until chosen.
        $this->assertSame('true', $xpath->query('//*[@id="week-tab-3"]')->item(0)->getAttribute('aria-selected'));
        $this->assertSame(5, $xpath->query('//*[@role="tabpanel" and @hidden]')->length);
        $this->assertFalse($xpath->query('//*[@id="week-panel-3"]')->item(0)->hasAttribute('hidden'));

        $dom = new \DOMDocument; @$dom->loadHTML($this->get('/timetable?week=5')->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertSame('true', $xpath->query('//*[@id="week-tab-5"]')->item(0)->getAttribute('aria-selected'));
        $this->assertSame(6, $xpath->query('//*[@role="tab"]')->length);
        $this->get('/timetable?q=no-such-session-anywhere')->assertOk()->assertSee('No entries match these filters.')->assertDontSee('role="tablist"', false);
    }

    public function test_activation_requires_google_verification_and_accepts_an_eight_character_password(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['services.recaptcha.enabled' => true, 'services.recaptcha.site_key' => 'test-site', 'services.recaptcha.secret_key' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake(['www.google.com/recaptcha/api/siteverify' => fn ($request) => Http::response(['success' => $request['response'] === 'verified'])]);
        foreach (['staff', 'facilitator'] as $role) {
            $user = $this->account($role, false);
            $token = 'captcha-'.$role;
            DB::table('invitations')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
            $url = '/invitation/'.$token;
            $this->get($url)->assertOk()->assertSee('g-recaptcha')->assertSee('test-site')->assertDontSee('test-secret');
            $data = ['first_name' => 'Test', 'surname' => 'Person', 'phone' => '08000000000', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh', 'privacy_acknowledged' => 1];
            $this->post($url, $data)->assertSessionHasErrors('g-recaptcha-response');
            $this->post($url, [...$data, 'g-recaptcha-response' => 'bad'])->assertSessionHasErrors('g-recaptcha-response');
            $this->assertFalse($user->fresh()->active);
            $this->post($url, [...$data, 'g-recaptcha-response' => 'verified'])->assertSessionHasNoErrors()->assertRedirect('/login');
            $this->assertTrue($user->fresh()->active);
        }
    }

    public function test_multiple_answer_questions_validate_render_score_and_review_correctly(): void
    {
        $admin = $this->account('admin');
        $activity = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Multiple answers quiz', 'instructions' => 'Select all', 'due_at' => now()->addDay(), 'attempts' => 5]);
        $questionUrl = '/activities/'.$activity->id.'/questions';
        $data = ['type' => 'multiple_answers', 'prompt' => 'Which two?', 'options' => "A\nB\nC", 'correct_answer' => "A\nC", 'points' => 5];
        $this->actingAs($admin)->post($questionUrl, [...$data, 'correct_answer' => "A\nD"])->assertUnprocessable();
        $this->post($questionUrl, $data)->assertSessionHasNoErrors()->assertRedirect();
        $question = $activity->questions()->firstOrFail();
        $this->get('/activities/'.$activity->id)->assertOk()->assertSee('Multiple Choice Multiple Answers');
        $activity->update(['published' => true]);
        foreach ([[['C', 'A'], 5], [['A'], 0], [['A', 'B', 'C'], 0]] as [$selected, $score]) {
            $staff = $this->account();
            $activity->participants()->attach($staff->id);
            $this->actingAs($staff)->post('/activities/'.$activity->id.'/start')->assertRedirect();
            $submission = Submission::where('activity_id', $activity->id)->latest('id')->firstOrFail();
            $url = '/submissions/'.$submission->id.'/complete';
            $this->get($url)->assertOk()->assertSee('type="checkbox"', false)->assertDontSee('correct_answer');
            $this->post($url, ['answers' => [$question->id => 'A']])->assertSessionHasErrors('answers.'.$question->id);
            $this->post($url, ['answers' => [$question->id => $selected]])->assertRedirect();
            $this->assertEquals($score, $submission->fresh()->score);
            $this->get('/activities/'.$activity->id)->assertOk();
        }
        $this->actingAs($admin)->get('/activities/'.$activity->id)->assertOk()->assertSee('A, C');
    }

    public function test_both_management_dashboards_show_real_staff_attendance_without_counting_facilitators(): void
    {
        $staff = $this->account(); $facilitator = $this->account('facilitator'); $admin = $this->account('admin');
        $day = ProgrammeDay::where('cancelled', false)->orderBy('date')->firstOrFail();
        $day->update(['attendance_required' => true]);
        $this->travelTo($day->date->copy()->setTime(18, 0));
        foreach ([$staff, $facilitator] as $user) DailyAttendance::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $day->id, 'user_id' => $user->id, 'status' => 'present']);
        $data = app(AttendanceTrend::class)->data();
        $this->assertSame(1, collect($data)->sum('present'));
        foreach ([$admin, $facilitator] as $user) $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Staff attendance trend')->assertSee('View attendance figures');
        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertDontSee('Staff attendance trend');
        $this->get('/profile')->assertSee('Back to dashboard');
        $this->actingAs($admin)->get('/manage/staff/create')->assertOk()->assertSee('Back to people');
    }
}
