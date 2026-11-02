<?php

namespace Tests\Feature;

use App\Models\{Activity, Badge, DailyAttendance, Programme, ProgrammeDay, Question, Resource, Submission, User};
use App\Services\{PersonMerge, ProgrammeReport, QuizAttemptService, TimetableImporter};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgrammeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Programme $programme;
    private ProgrammeDay $day;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        $this->travelTo(Carbon::parse('2026-11-02 17:00:00', 'Africa/Lagos'));
        $this->programme = Programme::first();
        $this->day = ProgrammeDay::where('number', 1)->firstOrFail();
        $this->day->update(['attendance_required' => true, 'cancelled' => false, 'checkin_opens' => '00:00', 'checkin_closes' => '23:59', 'checkout_opens' => '00:00', 'checkout_closes' => '23:59']);
        $this->admin = $this->person('Ada Admin', 'admin');
    }

    private function person(string $name, string $role = 'staff', array $extra = []): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'active' => true, ...$extra]);
        $user->programmes()->attach($this->programme->id);

        return $user;
    }

    private function signIn(User $user, string $in, ?string $out = null, string $method = 'scanner'): DailyAttendance
    {
        return DailyAttendance::create(['programme_id' => $this->programme->id, 'programme_day_id' => $this->day->id, 'user_id' => $user->id, 'staff_name' => $user->name,
            'attendance_date' => $this->day->date, 'checked_in_at' => Carbon::parse("2026-11-02 $in", 'Africa/Lagos'), 'checked_out_at' => $out ? Carbon::parse("2026-11-02 $out", 'Africa/Lagos') : null,
            'status' => 'present', 'signin_method' => $method, 'recorded_by' => $this->admin->id]);
    }

    private function badge(User $user, bool $printed): Badge
    {
        return Badge::create(['programme_id' => $this->programme->id, 'subject_key' => 'u:'.$user->id, 'user_id' => $user->id, 'category' => $user->role,
            'snapshot' => ['name' => $user->name], 'generated_at' => now(), 'generated_by' => $this->admin->id, 'printed_at' => $printed ? now() : null]);
    }

    public function test_the_report_counts_registration_attendance_materials_assessments_and_badges(): void
    {
        [$amina, $grace] = [$this->person('Amina Bello', 'staff', ['department' => 'Legal']), $this->person('Grace Okafor', 'staff', ['active' => false, 'department' => 'Legal'])];
        $this->person('Tunde Facilitator', 'facilitator');
        $this->signIn($amina, '08:10', '16:05');
        Resource::create(['programme_id' => $this->programme->id, 'title' => 'Handbook', 'category' => 'Guides', 'week' => 1, 'published' => true, 'url' => 'https://example.test/h', 'uploaded_by' => $this->admin->id]);
        Resource::create(['programme_id' => $this->programme->id, 'title' => 'Draft', 'category' => 'Guides', 'published' => false, 'url' => 'https://example.test/d', 'uploaded_by' => $this->admin->id]);
        $quiz = Activity::create(['programme_id' => $this->programme->id, 'kind' => 'quiz', 'title' => 'Day 1', 'instructions' => 'Go', 'due_at' => now()->addDay(), 'attempts' => 1, 'published' => true]);
        $quiz->participants()->attach([$amina->id, $grace->id]);
        $question = Question::create(['activity_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'True?', 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 4]);
        app(QuizAttemptService::class)->finalize(Submission::create(['activity_id' => $quiz->id, 'user_id' => $amina->id, 'attempt' => 1, 'started_at' => now()]), 'manual', [$question->id => 'True']);
        $this->badge($amina, true);

        $report = app(ProgrammeReport::class)->data();
        $this->assertSame([2, 1, 1, 1], [$report['registration']['staff'], $report['registration']['activated'], $report['registration']['awaiting'], $report['registration']['facilitators']]);
        $this->assertSame([['label' => 'Legal', 'value' => 2]], $report['registration']['departments']);
        $this->assertEquals(['signed_in' => 1, 'signed_out' => 1, 'expected' => 2, 'rate' => 50], array_intersect_key($report['attendance']['days'][0], array_flip(['signed_in', 'signed_out', 'expected', 'rate'])));
        $this->assertSame([2, 1], [$report['materials']['total'], $report['materials']['published']]);
        $this->assertSame(['assigned' => 2, 'submitted' => 1, 'outstanding' => 1, 'average' => 100], array_intersect_key($report['assessments']['rows'][0], array_flip(['assigned', 'submitted', 'outstanding', 'average'])));
        $this->assertEquals([1, 1], [$report['badges']['stages'][0]['value'], $report['badges']['stages'][1]['value']]);

        $this->actingAs($this->admin)->get('/reports')->assertOk()->assertSee('Programme report')->assertSee('Staff by department')
            ->assertSee('apexcharts.min.js', false)->assertSee('data-chart=', false)->assertSee('Show the figures');
        $this->get('/reports?tab=exports')->assertOk()->assertSee('Download report')->assertDontSee('data-chart=', false);
        $this->actingAs($amina)->get('/reports')->assertForbidden();
    }

    public function test_any_scan_counts_as_attended_even_on_a_day_not_set_to_take_attendance(): void
    {
        [$amina, $grace, $tunde] = [$this->person('Amina Bello'), $this->person('Grace Okafor'), $this->person('Tunde Ade')];
        $this->day->update(['attendance_required' => false]);
        // Scanned at a station left in sign-out mode: sign-outs only.
        foreach ([$amina, $grace] as $person) {
            DailyAttendance::create(['programme_id' => $this->programme->id, 'programme_day_id' => $this->day->id, 'user_id' => $person->id, 'staff_name' => $person->name,
                'attendance_date' => $this->day->date, 'checked_out_at' => now(), 'status' => 'pending', 'recorded_by' => $this->admin->id]);
        }
        $today = app(ProgrammeReport::class)->data()['attendance']['today'];
        $this->assertEquals(['expected' => 3, 'attended' => 2, 'signed_in' => 0, 'signed_out' => 2, 'rate' => 67], array_intersect_key($today, array_flip(['expected', 'attended', 'signed_in', 'signed_out', 'rate'])));
        $this->actingAs($this->admin)->get('/reports')->assertSee('Attended today')->assertSee('scanned in or out');
    }

    public function test_duplicate_profiles_merge_into_the_kept_one_and_combine_their_records(): void
    {
        $keep = $this->person('Maryam Umar', 'staff', ['email' => 'm.umar@example.test', 'department' => 'Legal']);
        $drop = $this->person('  maryam   UMAR ', 'staff', ['email' => 'maryam@example.test', 'active' => false, 'staff_number' => 'SCX-9']);
        $this->person('Maryam Bello');
        $this->signIn($keep, '09:00', '15:00');
        $this->signIn($drop, '08:15', '16:30', 'device');
        $this->badge($keep, false);
        $printed = $this->badge($drop, true);
        $dropQr = $drop->qr_code;
        $quiz = Activity::create(['programme_id' => $this->programme->id, 'kind' => 'quiz', 'title' => 'Day 1', 'instructions' => 'Go', 'due_at' => now()->addDay(), 'attempts' => 1, 'published' => true]);
        $quiz->participants()->attach([$keep->id, $drop->id]);
        Submission::create(['activity_id' => $quiz->id, 'user_id' => $keep->id, 'attempt' => 1, 'started_at' => now()]);
        Submission::create(['activity_id' => $quiz->id, 'user_id' => $drop->id, 'attempt' => 1, 'started_at' => now(), 'submitted_at' => now()]);

        $groups = app(PersonMerge::class)->candidates();
        $this->assertCount(1, $groups);
        $signature = app(PersonMerge::class)->signature($groups->first());
        $this->actingAs($this->admin)->get('/reports')->assertSee('Possible duplicate profiles')->assertSee('maryam@example.test')->assertSee('Merge into the selected profile');

        $this->post('/reports/duplicates/merge', ['signature' => $signature, 'keep' => $keep->id])->assertSessionHasErrors('confirmed');
        $this->post('/reports/duplicates/merge', ['signature' => $signature, 'keep' => $keep->id, 'confirmed' => 1])->assertRedirect('/reports#duplicates');

        $this->assertModelMissing($drop);
        $keep->refresh();
        $this->assertSame(['m.umar@example.test', 'SCX-9', 'Legal', $dropQr], [$keep->email, $keep->staff_number, $keep->department, $keep->qr_code]);
        $record = DailyAttendance::where('user_id', $keep->id)->sole();
        $this->assertSame(['08:15', '16:30', 'device'], [$record->checked_in_at->timezone('Africa/Lagos')->format('H:i'), $record->checked_out_at->timezone('Africa/Lagos')->format('H:i'), $record->signin_method]);
        $this->assertSame($printed->id, Badge::where('user_id', $keep->id)->sole()->id);
        $this->assertSame('u:'.$keep->id, $printed->fresh()->subject_key);
        $this->assertSame([1, 2], Submission::where('user_id', $keep->id)->orderBy('attempt')->pluck('attempt')->all());
        $this->assertSame(1, DB::table('activity_assignments')->where('user_id', $keep->id)->count());
        $this->assertSame(1, $keep->programmes()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.merge', 'subject' => (string) $keep->id]);
        $this->assertCount(0, app(PersonMerge::class)->candidates());
    }

    public function test_when_both_badges_are_printed_the_one_being_worn_moves_to_the_kept_account(): void
    {
        // Both accounts had a badge printed; she wears the one printed for the account being removed.
        $keep = $this->person('Rahinah Sirika', 'facilitator', ['email' => 'rahinahsirika@example.test']);
        $drop = $this->person('Rahinah Sirika', 'facilitator', ['email' => 'rahinahsirika+1@example.test', 'active' => false]);
        $this->badge($keep, true);
        $worn = $this->badge($drop, true);
        $wornQr = $drop->qr_code;
        $signature = app(PersonMerge::class)->signature(app(PersonMerge::class)->candidates()->first());
        $this->actingAs($this->admin)->get('/reports')->assertSee('Which one is this person wearing?')->assertSee('The badge printed for rahinahsirika+1@example.test');

        $this->post('/reports/duplicates/merge', ['signature' => $signature, 'keep' => $keep->id, 'confirmed' => 1])->assertSessionHasErrors('wearing');
        $this->assertModelExists($drop);
        $this->post('/reports/duplicates/merge', ['signature' => $signature, 'keep' => $keep->id, 'wearing' => $drop->id, 'confirmed' => 1])->assertRedirect('/reports#duplicates');

        $this->assertModelMissing($drop);
        $keep->refresh();
        $this->assertSame(['rahinahsirika@example.test', true, $wornQr], [$keep->email, (bool) $keep->active, $keep->qr_code]);
        $this->assertSame($worn->id, Badge::where('user_id', $keep->id)->sole()->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.merge', 'subject' => (string) $keep->id]);
        $this->assertSame('rahinahsirika+1@example.test', json_decode(DB::table('audit_logs')->where('action', 'user.merge')->value('after'), true)['badge_from_email']);
    }

    public function test_groups_can_be_marked_as_different_people_and_only_admins_reconcile(): void
    {
        $this->person('Komommo Okoi', 'facilitator'); $other = $this->person('Komommo Okoi', 'facilitator');
        $signature = app(PersonMerge::class)->signature(app(PersonMerge::class)->candidates()->first());
        $manager = $this->person('Report Viewer', 'facilitator', ['permissions' => ['reports']]);
        $this->actingAs($manager)->get('/reports')->assertOk()->assertSee('Only administrators can merge profiles.')->assertDontSee('Merge into the selected profile');
        $this->post('/reports/duplicates/merge', ['signature' => $signature, 'keep' => $other->id, 'confirmed' => 1])->assertForbidden();

        $this->actingAs($this->admin)->post('/reports/duplicates/dismiss', ['signature' => $signature])->assertRedirect('/reports#duplicates');
        $this->assertCount(0, app(PersonMerge::class)->candidates());
        $this->assertSame(2, User::where('name', 'Komommo Okoi')->count());
        // A new matching profile changes the group, so it is suggested again.
        $this->person('Komommo  Okoi', 'staff');
        $this->assertCount(1, app(PersonMerge::class)->candidates());

        config(['attendance_sync.url' => 'https://live.example.test', 'attendance_sync.origin' => 'venue']);
        $this->get('/reports')->assertSee('Reconcile duplicates on the live site.');
        $signature = app(PersonMerge::class)->signature(app(PersonMerge::class)->candidates()->first());
        $this->post('/reports/duplicates/merge', ['signature' => $signature, 'keep' => $other->id, 'confirmed' => 1])->assertStatus(422);
    }
}
