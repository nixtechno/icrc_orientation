<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Programme;
use App\Models\ProgrammeDay;
use App\Models\ProgrammeSession;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrientationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        Notification::fake();
    }

    private function account(string $role = 'staff', array $permissions = []): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => $role, 'active' => true, 'permissions' => $permissions])->save();
        $u->programmes()->attach(Programme::first()->id);

        return $u;
    }

    public function test_source_import_is_complete_idempotent_and_retains_ambiguities(): void
    {
        $count = ProgrammeSession::count();
        $this->assertSame(30, ProgrammeDay::count());
        $this->assertSame(6, Programme::first()->weeks()->count());
        $this->assertSame(187, $count);
        app(TimetableImporter::class)->run();
        $this->assertSame($count, ProgrammeSession::count());
        $s = ProgrammeSession::where('source_key', '2026-d4-2')->firstOrFail();
        $this->assertSame(105, $s->actual_minutes);
        $this->assertSame('30 minutes', $s->source['cells'][1]);
        $this->assertContains('Stated duration differs from time range', $s->warnings);
        $bad = ProgrammeSession::where('source_key', '2026-d8-1')->firstOrFail();
        $this->assertSame('TBB', $bad->facilitator);
        $this->assertContains('Invalid stated duration', $bad->warnings);
        $dinner = ProgrammeSession::where('type', 'dinner')->firstOrFail();
        $this->assertSame('2026-12-10 16:00', $dinner->starts_at->format('Y-m-d H:i'));
        $this->assertNull($dinner->ends_at);
        $free = ProgrammeSession::where('type', 'free_day')->firstOrFail();
        $this->assertNull($free->starts_at);
        $this->assertFalse($free->attendance_required);
        $this->assertSame(0, ProgrammeSession::where('attendance_required', true)->count());
    }

    public function test_public_and_authenticated_pages_render_and_roles_are_enforced(): void
    {
        $this->get('/')->assertOk()->assertSee('Building knowledge.');
        $this->get('/login')->assertOk();
        $staff = $this->account();
        $this->actingAs($staff);
        $this->get('/dashboard')->assertOk()->assertSee('Your six-week journey')->assertSee('My progress')->assertDontSee('Action centre');
        foreach (['/dashboard', '/timetable', '/attendance', '/activities', '/resources', '/announcements', '/profile'] as $path) {
            $this->get($path)->assertOk()->assertSee('portal-frame', false)->assertSee('portal-navigation', false)->assertDontSee('class="site-footer"', false)->assertDontSee('class="institution-bar"', false);
        }foreach (['/manage/staff', '/settings', '/audit', '/reports', '/facilitators'] as $path) {
            $this->get($path)->assertForbidden();
        }$super = $this->account('facilitator', ['schedule', 'facilitators']);
        $this->actingAs($super)->get('/facilitators')->assertOk();
        $this->get('/dashboard')->assertOk()->assertSee('Facilitator Dashboard')->assertSee('Timetable entries to review')->assertDontSee('Submissions awaiting grading')->assertDontSee('Your six-week journey')->assertDontSee('My progress');
        $this->get('/manage/staff')->assertForbidden();
        $this->get('/settings')->assertForbidden();
        $admin = $this->account('admin');
        $this->actingAs($admin);
        $this->get('/dashboard')->assertOk()->assertSee('Admin Dashboard')->assertSee('Action centre')->assertSee('Submissions awaiting grading')->assertSeeInOrder(['Participants','Active accounts','Awaiting activation','Entries to review','Live daily attendance','Action centre'])->assertDontSee('UPCOMING')->assertDontSee(chr(239).chr(191).chr(189))->assertDontSee('Your six-week journey')->assertDontSee('My progress')->assertDontSee('Attendance overview');
        foreach (['/manage/staff', '/settings', '/audit', '/reports', '/facilitators'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_invitation_is_single_use_expiring_and_revocable(): void
    {
        $u = $this->account();
        $u->forceFill(['active' => false])->save();
        DB::table('invitations')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', 'one-time'), 'expires_at' => now()->addHour()]);
        $this->get('/invitation/one-time')->assertOk();
        $this->post('/invitation/one-time', ['first_name' => 'Invited', 'surname' => 'Person', 'phone' => '08000000000', 'privacy_acknowledged' => 1, 'name' => 'Invited person', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123'])->assertRedirect('/login');
        $this->assertTrue($u->fresh()->active);
        $this->post('/invitation/one-time', ['name' => 'Again', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123'])->assertGone();
        DB::table('invitations')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute()]);
        $this->get('/invitation/expired')->assertNotFound();
    }

    public function test_private_resources_require_enrolment_and_publication(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('resources/private.pdf', 'private');
        $u = $this->account();
        $id = DB::table('resources')->insertGetId(['programme_id' => Programme::first()->id, 'uploaded_by' => $u->id, 'title' => 'Private file', 'path' => 'resources/private.pdf', 'filename' => 'private.pdf', 'published' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/resources/'.$id.'/download')->assertRedirect('/login');
        $this->actingAs($u)->get('/resources/'.$id.'/download')->assertForbidden();
        DB::table('resources')->where('id', $id)->update(['published' => true]);
        $this->get('/resources/'.$id.'/download')->assertOk();
        $u->programmes()->detach();
        $this->get('/resources/'.$id.'/download')->assertForbidden();
    }

    public function test_assessment_result_and_other_participants_work_remain_private(): void
    {
        $u = $this->account();
        $other = $this->account();
        $a = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Test quiz', 'instructions' => 'Respond', 'due_at' => now()->addDay(), 'published' => true]);
        $a->participants()->attach([$u->id, $other->id]);
        Question::create(['activity_id' => $a->id, 'type' => 'multiple_choice', 'prompt' => 'Select the answer', 'options' => ['Option A', 'SECRET CORRECT OPTION'], 'correct_answer' => 'SECRET CORRECT OPTION', 'points' => 1]);
        $s = Submission::create(['activity_id' => $a->id, 'user_id' => $u->id, 'attempt' => 1, 'started_at' => now(), 'submitted_at' => now(), 'score' => 1, 'feedback' => 'PRIVATE FEEDBACK']);
        $otherSubmission = Submission::create(['activity_id' => $a->id, 'user_id' => $other->id, 'attempt' => 1, 'started_at' => now(), 'body' => 'Other staff private work']);
        $this->actingAs($u)->get('/activities/'.$a->id)->assertOk()->assertDontSee('PRIVATE FEEDBACK')->assertDontSee('Other staff private work')->assertDontSee('Correct answer:');
        $this->get('/submissions/'.$otherSubmission->id.'/complete')->assertForbidden();
        $this->get('/submissions/'.$otherSubmission->id.'/download')->assertForbidden();
        $s->update(['released_at' => now()]);
        $this->get('/activities/'.$a->id)->assertSee('PRIVATE FEEDBACK');
    }

    public function test_reports_respect_department_filter_and_permissions(): void
    {
        $admin = $this->account('admin');
        $a = $this->account();
        $a->forceFill(['name' => 'Included staff', 'department' => 'Finance'])->save();
        $b = $this->account();
        $b->forceFill(['name' => 'Excluded staff', 'department' => 'ICT'])->save();
        $response = $this->actingAs($admin)->get('/reports/export?report=staff&department=Finance');
        $response->assertOk();
        $data = $response->streamedContent();
        $this->assertStringContainsString('Included staff', $data);
        $this->assertStringNotContainsString('Excluded staff', $data);
        $this->actingAs($a)->get('/reports/export?report=staff')->assertForbidden();
    }
}
