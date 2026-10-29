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
        $admin = $this->account('admin'); $staff = $this->account();
        $activity = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Multiple answers quiz', 'instructions' => 'Select all', 'due_at' => now()->addDay(), 'attempts' => 5]);
        $activity->participants()->attach($staff->id);
        $questionUrl = '/activities/'.$activity->id.'/questions';
        $data = ['type' => 'multiple_answers', 'prompt' => 'Which two?', 'options' => "A\nB\nC", 'correct_answer' => "A\nC", 'points' => 5];
        $this->actingAs($admin)->post($questionUrl, [...$data, 'correct_answer' => "A\nD"])->assertUnprocessable();
        $this->post($questionUrl, $data)->assertSessionHasNoErrors()->assertRedirect();
        $question = $activity->questions()->firstOrFail();
        $this->get('/activities/'.$activity->id)->assertOk()->assertSee('Multiple Choice Multiple Answers');
        $activity->update(['published' => true]);
        foreach ([[['C', 'A'], 5], [['A'], 0], [['A', 'B', 'C'], 0]] as [$selected, $score]) {
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
