<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Programme;
use App\Models\ProgrammeSession;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ProgrammeUpdate;
use App\Services\TimetableImporter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationAuditTest extends TestCase
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
        $user = User::factory()->create(['role' => $role, 'permissions' => $permissions, 'active' => true, 'password' => 'OriginalPassword123']);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    public function test_login_rejects_invalid_and_inactive_accounts_and_logout_protects_private_pages(): void
    {
        $user = $this->account();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->forceFill(['active' => false])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'OriginalPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $user->forceFill(['active' => true])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'OriginalPassword123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        foreach (['/dashboard', '/profile', '/timetable', '/attendance', '/resources', '/reports', '/badges'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_password_reset_delivers_a_notification_validates_tokens_and_is_single_use(): void
    {
        $user = $this->account();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('success');
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => 'invalid-token', 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'];
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $payload['token'] = $token;
        $this->post('/reset-password', $payload)->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewPassword12345', $user->fresh()->password));
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_login_form_and_token_refresh_are_uncacheable_and_use_the_current_session(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-login-form', false)
            ->assertSee('login.js')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->withSession(['_token' => 'fresh-token-for-this-session'])
            ->getJson('/login/csrf-token')->assertOk()->assertExactJson(['token' => 'fresh-token-for-this-session'])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_staff_cannot_mutate_management_modules_and_facilitators_need_each_permission(): void
    {
        $staff = $this->account();
        $this->actingAs($staff);
        foreach (['/resources', '/announcements', '/settings', '/activities', '/facilitators', '/manage/staff', '/settings/registration', '/badges/generate', '/badges/settings'] as $path) {
            $this->post($path, [])->assertForbidden();
        }
        $staff->forceFill(['active' => false])->save();
        $this->get('/dashboard')->assertForbidden();
        $this->actingAs($this->account('facilitator', ['resources']))->get('/resources')->assertOk()->assertSee('Add a resource');
        $this->post('/announcements', [])->assertForbidden();
        $this->get('/reports')->assertForbidden();
        $this->get('/manage/supplement')->assertForbidden();
    }

    public function test_resources_upload_publish_filter_download_and_unpublish(): void
    {
        Storage::fake('local');
        $admin = $this->account('admin');
        $staff = $this->account();
        $this->actingAs($admin)->post('/resources', ['title' => 'QA training image', 'week' => 2, 'category' => 'Reference', 'file' => UploadedFile::fake()->image('training.png')])->assertSessionHasNoErrors()->assertRedirect();
        $resource = DB::table('resources')->where('title', 'QA training image')->first();
        $this->assertNotNull($resource);
        Storage::disk('local')->assertExists($resource->path);
        $this->actingAs($staff)->get('/resources')->assertDontSee('QA training image');
        $this->get('/resources/'.$resource->id.'/download')->assertForbidden();
        $this->actingAs($admin)->post('/resources/'.$resource->id.'/publication')->assertRedirect();
        $this->actingAs($staff)->get('/resources?week=2&category=Reference')->assertSee('QA training image');
        $this->get('/resources?week=1')->assertDontSee('QA training image');
        $this->get('/resources/'.$resource->id.'/download')->assertOk()->assertDownload('training.png');
        $this->actingAs($admin)->post('/resources/'.$resource->id.'/publication')->assertRedirect();
        $this->actingAs($staff)->get('/resources/'.$resource->id.'/download')->assertForbidden();
        $this->actingAs($admin)->post('/resources', ['title' => 'Invalid', 'url' => 'javascript:alert(1)'])->assertSessionHasErrors('url');
    }

    public function test_announcements_respect_audience_drafts_schedule_archive_and_escape_markup(): void
    {
        $admin = $this->account('admin');
        $staff = $this->account();
        $staff->forceFill(['department' => 'ICT', 'group_name' => 'A'])->save();
        foreach ([['QA visible', now()->subMinute(), 'ICT', 'A'], ['QA other department', now()->subMinute(), 'Finance', null], ['QA future', now()->addDay(), null, null], ['QA draft', null, null, null]] as [$title,$published,$department,$group]) {
            $this->actingAs($admin)->post('/announcements', ['title' => $title, 'body' => '<script>alert("qa")</script>', 'published_at' => $published?->toDateTimeString(), 'department' => $department, 'group_name' => $group])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->actingAs($staff)->get('/announcements')->assertOk()->assertSee('QA visible')->assertDontSee('QA other department')->assertDontSee('QA future')->assertDontSee('QA draft')->assertDontSee('<script>alert("qa")</script>', false);
        $id = DB::table('announcements')->where('title', 'QA visible')->value('id');
        $this->actingAs($admin)->post('/announcements/'.$id.'/archive')->assertRedirect();
        $this->actingAs($staff)->get('/announcements')->assertDontSee('QA visible');
    }

    public function test_quiz_creation_publication_submission_grading_release_and_attempt_limit(): void
    {
        $admin = $this->account('admin');
        $staff = $this->account();
        $this->actingAs($admin)->post('/activities', ['kind' => 'quiz', 'title' => 'QA full quiz', 'instructions' => 'Choose one answer', 'due_at' => now()->addDay()->toDateTimeString(), 'attempts' => 1, 'assignment_mode' => 'selected', 'participants' => [$staff->id]])->assertSessionHasNoErrors()->assertRedirect();
        $activity = Activity::where('title', 'QA full quiz')->firstOrFail();
        $this->post('/activities/'.$activity->id.'/publish')->assertUnprocessable();
        $this->post('/activities/'.$activity->id.'/questions', ['type' => 'multiple_choice', 'prompt' => 'Choose B', 'options' => "A\nB", 'correct_answer' => 'B', 'points' => 5])->assertSessionHasNoErrors()->assertRedirect();
        $question = $activity->questions()->firstOrFail();
        $this->post('/activities/'.$activity->id.'/publish')->assertRedirect();
        $this->actingAs($staff)->post('/activities/'.$activity->id.'/start')->assertRedirect();
        $submission = Submission::where('activity_id', $activity->id)->firstOrFail();
        $this->post('/activities/'.$activity->id.'/start')->assertRedirect('/submissions/'.$submission->getRouteKey().'/complete');
        $this->assertSame(1, Submission::where('activity_id', $activity->id)->count());
        $this->get('/submissions/'.$submission->id.'/complete')->assertOk()->assertSee('Choose B')->assertDontSee('correct_answer');
        $this->post('/submissions/'.$submission->id.'/complete', ['answers' => [$question->id => 'B', 999999 => 'injected']])->assertRedirect('/activities/'.$activity->getRouteKey());
        $this->assertEquals(5, $submission->fresh()->score);
        $this->assertArrayNotHasKey(999999, $submission->fresh()->answers);
        $this->post('/submissions/'.$submission->id.'/complete', ['answers' => [$question->id => 'A']])->assertRedirect('/activities/'.$activity->getRouteKey())->assertSessionHas('success', 'This attempt has already been submitted. Your saved answers have not changed.');
        $this->assertEquals(5, $submission->fresh()->score);
        $this->assertSame('B', $submission->fresh()->answers[$question->id]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'submission.submit')->where('subject', (string) $submission->id)->count());
        $this->get('/submissions/'.$submission->id.'/complete')->assertRedirect('/activities/'.$activity->getRouteKey());
        $this->post('/activities/'.$activity->id.'/start')->assertUnprocessable();
        $this->get('/activities/'.$activity->id)->assertDontSee('Released score:');
        $this->actingAs($admin)->post('/submissions/'.$submission->id.'/review', ['score' => 5, 'feedback' => 'QA released feedback', 'release' => 1])->assertRedirect();
        $this->actingAs($staff)->get('/activities/'.$activity->id)->assertSee('Released score: 5')->assertSee('QA released feedback');
        $activity->update(['due_at' => now()->subMinute()]);
        $this->post('/submissions/'.$submission->id.'/complete', [])->assertRedirect('/activities/'.$activity->getRouteKey());
        $this->assertSame('B', $submission->fresh()->answers[$question->id]);
        $this->actingAs($this->account())->post('/submissions/'.$submission->id.'/complete', [])->assertForbidden();
    }

    public function test_objective_questions_without_an_answer_fail_validation_instead_of_crashing(): void
    {
        $activity = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'QA validation', 'instructions' => 'Test', 'due_at' => now()->addDay()]);
        $this->actingAs($this->account('admin'))->post('/activities/'.$activity->id.'/questions', ['type' => 'multiple_choice', 'prompt' => 'No answer', 'options' => "A\nB", 'points' => 1])->assertUnprocessable();
        $this->assertSame(0, $activity->questions()->count());
    }

    public function test_assignments_and_presentations_accept_files_keep_downloads_private_and_enforce_deadlines(): void
    {
        Storage::fake('local');
        $staff = $this->account();
        $other = $this->account();
        foreach (['assignment', 'presentation'] as $kind) {
            $activity = Activity::create(['programme_id' => Programme::first()->id, 'kind' => $kind, 'title' => 'QA '.$kind, 'instructions' => 'Submit your work', 'published' => true, 'available_at' => now()->subHour(), 'due_at' => now()->addHour(), 'attempts' => 1, 'time_limit' => 10]);
            $activity->participants()->attach($staff->id);
            $this->actingAs($other)->post('/activities/'.$activity->id.'/start')->assertForbidden();
            $activity->update(['available_at' => now()->addMinutes(5)]);
            $this->actingAs($staff)->post('/activities/'.$activity->id.'/start')->assertUnprocessable();
            $activity->update(['available_at' => now()->subHour()]);
            $this->post('/activities/'.$activity->id.'/start')->assertRedirect();
            $submission = $activity->submissions()->firstOrFail();
            $this->from('/submissions/'.$submission->id.'/complete')->post('/submissions/'.$submission->id.'/complete', [])->assertSessionHasErrors('body');
            $this->post('/submissions/'.$submission->id.'/complete', ['file' => UploadedFile::fake()->image('work.png'), 'body' => 'QA submitted work'])->assertSessionHasNoErrors()->assertRedirect('/activities/'.$activity->getRouteKey());
            $this->get('/submissions/'.$submission->id.'/download')->assertOk()->assertDownload('work.png');
            $this->actingAs($other)->get('/submissions/'.$submission->id.'/download')->assertForbidden();
            $this->actingAs($this->account('admin'))->get('/submissions/'.$submission->id.'/download')->assertOk();
            $this->assertNull($submission->fresh()->score);
        }
        $activity->update(['attempts' => 2]);
        $this->actingAs($staff)->post('/activities/'.$activity->id.'/start')->assertRedirect();
        $attempt = $activity->submissions()->whereNull('submitted_at')->firstOrFail();
        $attempt->update(['started_at' => now()->subMinutes(11)]);
        $this->post('/submissions/'.$attempt->id.'/complete', ['body' => 'Too late'])->assertUnprocessable();
        $activity->update(['due_at' => now()->subMinute()]);
        $this->post('/activities/'.$activity->id.'/start')->assertUnprocessable();
    }

    public function test_profile_password_change_requires_current_password_and_preserves_official_fields(): void
    {
        $staff = $this->account();
        $staff->forceFill(['department' => 'ICT', 'staff_number' => 'QA001'])->save();
        $payload = ['first_name' => 'QA', 'surname' => 'Tester', 'phone' => '08012345678', 'department' => 'Finance', 'staff_number' => 'OVERRIDE', 'current_password' => 'wrong', 'password' => 'ChangedPassword123', 'password_confirmation' => 'ChangedPassword123'];
        $this->actingAs($staff)->post('/profile', $payload)->assertSessionHasErrors('current_password');
        $payload['current_password'] = 'OriginalPassword123';
        $this->post('/profile', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $staff->refresh();
        $this->assertTrue(Hash::check('ChangedPassword123', $staff->password));
        $this->assertSame('ICT', $staff->department);
        $this->assertSame('QA001', $staff->staff_number);
        $this->assertSame('QA Tester', $staff->name);
    }

    public function test_mark_notifications_read_affects_only_the_current_account(): void
    {
        $staff = $this->account(); $other = $this->account();
        foreach ([$staff, $other] as $user) {
            $user->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => ProgrammeUpdate::class, 'data' => ['title' => 'QA notification', 'body' => 'Test message']]);
        }
        $this->actingAs($staff)->post('/notifications/read')->assertRedirect();
        $this->assertSame(0, $staff->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_timetable_edits_recalculate_actual_duration_without_changing_original_source(): void
    {
        $session = ProgrammeSession::firstOrFail();
        $source = $session->source;
        $this->actingAs($this->account('admin'))->post('/manage/sessions/'.$session->id, ['title' => 'QA revised session', 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 11:00:00', 'type' => 'session', 'status' => 'draft'])->assertSessionHasNoErrors()->assertRedirect('/timetable');
        $this->assertSame(120, $session->fresh()->actual_minutes);
        $this->assertSame($source, $session->fresh()->source);
        $this->get('/timetable?q=QA+revised+session&week=1&date=2026-11-02')->assertOk()->assertSee('QA revised session');
        $this->actingAs($this->account())->get('/timetable?q=QA+revised+session')->assertDontSeeText('QA revised session')->assertSee('No entries match these filters.');
    }

    public function test_reports_export_all_types_and_real_csv_xlsx_pdf_and_print_documents(): void
    {
        $this->account();
        $this->actingAs($this->account('admin'));
        foreach (['staff', 'attendance', 'assessments', 'progress', 'schedule', 'activation'] as $type) {
            $response = $this->get('/reports/export?report='.$type.'&format=csv')->assertOk()->assertDownload();
            $this->assertNotEmpty($response->streamedContent());
        }
        $xlsx = $this->get('/reports/export?report=staff&format=xlsx')->assertOk()->assertDownload();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
        $pdf = $this->get('/reports/export?report=staff&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get('/reports/export?report=staff&print=1')->assertOk()->assertSee('Print / save as PDF');
        $this->get('/reports/export?report=not-a-report')->assertSessionHasErrors('report');
        $this->get('/reports/export?report=staff&from=2026-12-01&to=2026-11-01')->assertSessionHasErrors('to');
    }

    public function test_settings_validate_save_preserve_attendance_security_and_audit_changes(): void
    {
        $programme = Programme::first();
        $programme->update(['settings' => [...$programme->settings, 'attendance_security' => ['latitude' => 9, 'longitude' => 7]]]);
        $this->actingAs($this->account('admin'))->post('/settings', ['title' => 'QA programme title', 'starts_on' => '2026-11-02', 'ends_on' => '2026-12-11', 'timezone' => 'Africa/Lagos', 'about' => 'QA about', 'venue' => 'QA venue', 'lateness_minutes' => 15, 'window_minutes' => 60])->assertSessionHasNoErrors()->assertRedirect();
        $programme->refresh();
        $this->assertSame('QA programme title', $programme->title);
        $this->assertSame(9, $programme->settings['attendance_security']['latitude']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.update']);
        $this->post('/settings', ['title' => 'Invalid'])->assertSessionHasErrors(['starts_on', 'ends_on', 'timezone']);
    }

    public function test_dashboard_and_session_management_do_not_expose_another_programme(): void
    {
        $p = Programme::first();
        $other = $p->replicate(); $other->code = 'QA-OTHER'; $other->save();
        $week = $p->weeks()->firstOrFail()->replicate(); $week->programme_id = $other->id; $week->save();
        $day = $p->weeks()->first()->days()->first()->replicate(); $day->programme_week_id = $week->id; $day->number = 999; $day->date = '2027-01-01'; $day->save();
        $session = ProgrammeSession::first()->replicate();
        $session->fill(['programme_day_id' => $day->id, 'source_key' => 'qa-other-session', 'title' => 'QA OTHER PROGRAMME PRIVATE SESSION', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2), 'status' => 'published']); $session->save();
        $this->actingAs($this->account('admin'))->get('/dashboard')->assertOk()->assertDontSee('QA OTHER PROGRAMME PRIVATE SESSION');
        $this->get('/manage/sessions/'.$session->id)->assertNotFound();
        $this->post('/manage/sessions/'.$session->id, ['title' => 'Changed', 'type' => 'session', 'status' => 'published'])->assertNotFound();
        $this->assertSame('QA OTHER PROGRAMME PRIVATE SESSION', $session->fresh()->title);
    }

    public function test_public_and_portal_pages_reference_existing_local_assets_without_corrupt_characters(): void
    {
        foreach (['/', '/login', '/forgot-password'] as $path) {
            $this->checkAssets($this->get($path)->assertOk()->getContent());
        }
        $this->actingAs($this->account('admin'));
        foreach (['/dashboard', '/profile', '/timetable', '/settings', '/attendance', '/manage/staff/create'] as $path) {
            $this->checkAssets($this->get($path)->assertOk()->getContent());
        }
    }

    private function checkAssets(string $html): void
    {
        $this->assertStringNotContainsString("\u{FFFD}", $html);
        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);
        foreach (array_unique($matches[1]) as $url) {
            $path = parse_url(html_entity_decode($url), PHP_URL_PATH);
            if ($path && preg_match('/\.(?:css|js|png|jpg|jpeg|svg|ttf|woff2)$/i', $path)) {
                $this->assertFileExists(public_path(ltrim($path, '/')), 'Missing asset: '.$url);
            }
        }
    }
}
