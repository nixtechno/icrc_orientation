<?php

namespace Tests\Feature;

use App\Models\{Activity, Badge, DailyAttendance, Programme, ProgrammeDay, Submission, User};
use App\Services\{AttendanceSync, TimetableImporter};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Tests\TestCase;

class DeletePersonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run(); Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'Africa/Lagos'));
        $this->programme = Programme::first();
        $this->admin = $this->person('admin', ['email' => 'operator@example.test']);
    }

    private function person(string $role = 'staff', array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, ...$extra]);
        $user->programmes()->attach($this->programme->id);

        return $user;
    }

    public function test_an_admin_deletes_a_person_with_their_records_after_saving_a_backup(): void
    {
        $staff = $this->person('staff', ['name' => 'Test Account', 'email' => 'test.account@example.test']);
        $colleague = $this->person();
        $day = ProgrammeDay::where('number', 1)->firstOrFail();
        DailyAttendance::create(['programme_id' => $this->programme->id, 'programme_day_id' => $day->id, 'user_id' => $staff->id, 'staff_name' => $staff->name, 'attendance_date' => $day->date, 'checked_in_at' => now(), 'status' => 'present', 'recorded_by' => $this->admin->id]);
        // A scan the person recorded for a colleague stays, without the link to them.
        $scanned = DailyAttendance::create(['programme_id' => $this->programme->id, 'programme_day_id' => $day->id, 'user_id' => $colleague->id, 'staff_name' => $colleague->name, 'attendance_date' => $day->date, 'checked_in_at' => now(), 'status' => 'present', 'recorded_by' => $staff->id, 'signin_scanned_by' => $staff->id]);
        $quiz = Activity::create(['programme_id' => $this->programme->id, 'kind' => 'assignment', 'title' => 'Essay', 'instructions' => 'Write', 'due_at' => now()->addDay(), 'attempts' => 1, 'published' => true]);
        $quiz->participants()->attach($staff->id);
        Storage::disk('local')->put('submissions/essay.pdf', 'pdf');
        Submission::create(['activity_id' => $quiz->id, 'user_id' => $staff->id, 'attempt' => 1, 'started_at' => now(), 'submitted_at' => now(), 'path' => 'submissions/essay.pdf']);
        Badge::create(['programme_id' => $this->programme->id, 'subject_key' => 'u:'.$staff->id, 'user_id' => $staff->id, 'category' => 'staff', 'snapshot' => ['name' => $staff->name], 'generated_at' => now(), 'generated_by' => $this->admin->id]);

        $url = '/manage/staff/'.$staff->slug;
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Delete this person')->assertSee('1 attendance records, 1 submissions, 1 badges and 1 assignments');
        $this->delete($url)->assertSessionHasErrors('confirmed');
        $this->assertModelExists($staff);
        $this->delete($url, ['confirmed' => 1])->assertRedirect('/manage/staff')->assertSessionHas('success', fn ($m) => str_contains($m, 'Test Account (test.account@example.test) was deleted'));

        $this->assertModelMissing($staff);
        $this->assertSame([0, 0, 0, 0], [DailyAttendance::where('user_id', $staff->id)->count(), Submission::count(), Badge::count(), DB::table('enrolments')->where('user_id', $staff->id)->count()]);
        $this->assertSame([null, null], [$scanned->fresh()->recorded_by, $scanned->fresh()->signin_scanned_by]);
        Storage::disk('local')->assertMissing('submissions/essay.pdf');
        $backup = DB::table('audit_logs')->where('action', 'user.delete')->value('after');
        $file = json_decode($backup, true)['backup'];
        Storage::disk('local')->assertExists($file);
        $saved = json_decode(Storage::disk('local')->get($file), true);
        $this->assertSame(['test.account@example.test', 1, 1], [$saved['user']['email'], count($saved['submissions']), count($saved['badges'])]);
    }

    public function test_only_admins_delete_others_on_the_live_site_and_people_others_depend_on_are_kept(): void
    {
        $staff = $this->person();
        $manager = $this->person('facilitator', ['permissions' => ['staff']]);
        $this->actingAs($manager)->get('/manage/staff/'.$staff->slug)->assertOk()->assertDontSee('Delete this person');
        $this->delete('/manage/staff/'.$staff->slug, ['confirmed' => 1])->assertForbidden();

        $this->actingAs($this->admin)->get('/manage/staff/'.$this->admin->slug)->assertDontSee('Delete this person');
        $this->delete('/manage/staff/'.$this->admin->slug, ['confirmed' => 1])->assertStatus(422);

        // Someone who set up staff groups can only be deactivated.
        $organiser = $this->person('facilitator');
        \App\Models\AssessmentGroup::create(['programme_id' => $this->programme->id, 'name' => 'Group A', 'created_by' => $organiser->id]);
        $this->get('/manage/staff/'.$organiser->slug)->assertSee('can only be deactivated')->assertDontSee('name="confirmed"', false);
        $this->delete('/manage/staff/'.$organiser->slug, ['confirmed' => 1])->assertSessionHasErrors('confirmed');
        $this->assertModelExists($organiser);

        config(['attendance_sync.url' => 'https://live.example.test', 'attendance_sync.origin' => 'venue']);
        $this->delete('/manage/staff/'.$staff->slug, ['confirmed' => 1])->assertStatus(422);
        $this->assertModelExists($staff);
    }

    public function test_venues_delete_their_copy_on_the_next_sync_but_not_someone_added_again_later(): void
    {
        config(['attendance_sync.token' => str_repeat('t', 40), 'attendance_sync.url' => null, 'attendance_sync.origin' => 'online']);
        $gone = $this->person('staff', ['email' => 'gone@example.test']);
        $this->actingAs($this->admin)->delete('/manage/staff/'.$gone->slug, ['confirmed' => 1])->assertRedirect('/manage/staff');
        $deletions = $this->withToken(str_repeat('t', 40))->getJson('/api/attendance-sync/snapshot')->assertOk()->json('deletions');
        $this->assertSame('gone@example.test', $deletions[0]['email']);

        // The venue still has its copy, and an unrelated account that reuses an email deleted earlier.
        config(['attendance_sync.url' => 'https://live.example.test', 'attendance_sync.origin' => 'venue']);
        $copy = $this->person('staff', ['email' => 'gone@example.test', 'created_at' => now()->subDay()]);
        $readded = $this->person('staff', ['email' => 'returned@example.test']);
        $snapshot = ['cursor' => now()->toIso8601String(), 'programme' => ['starts_on' => '2026-11-02', 'ends_on' => '2026-12-11'], 'days' => [], 'people' => [], 'badges' => [], 'attendance' => [],
            'deletions' => [...$deletions, ['email' => 'returned@example.test', 'deleted_at' => now()->subDays(2)->toIso8601String()]]];
        Http::fake(['*/ping' => Http::response(['time' => now()->toIso8601String()]), '*/upload' => Http::response(['records' => [], 'logs' => []]), '*/snapshot*' => Http::response($snapshot)]);
        $this->assertTrue(app(AttendanceSync::class)->run()['online']);
        $this->assertModelMissing($copy);
        $this->assertModelExists($readded);
    }
}
