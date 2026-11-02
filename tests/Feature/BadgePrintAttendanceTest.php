<?php

namespace Tests\Feature;

use App\Models\{Badge, DailyAttendance, Programme, ProgrammeDay, User};
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgePrintAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Programme $programme;
    private ProgrammeDay $day;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        $this->travelTo(Carbon::parse('2026-11-02 18:00:00', 'Africa/Lagos'));
        $this->programme = Programme::first();
        $this->day = ProgrammeDay::where('number', 1)->firstOrFail();
        $this->admin = $this->person('admin');
    }

    private function person(string $role = 'staff'): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        $user->programmes()->attach($this->programme->id);

        return $user;
    }

    private function printedAt(User $user, ?string $time): Badge
    {
        return Badge::create(['programme_id' => $this->programme->id, 'subject_key' => 'u:'.$user->id, 'user_id' => $user->id, 'category' => $user->role, 'snapshot' => ['name' => $user->name],
            'generated_at' => now(), 'generated_by' => $this->admin->id, 'printed_at' => $time ? Carbon::parse($time, 'Africa/Lagos') : null]);
    }

    private function record(User $user, array $fields): DailyAttendance
    {
        return DailyAttendance::create(['programme_id' => $this->programme->id, 'programme_day_id' => $this->day->id, 'user_id' => $user->id, 'staff_name' => $user->name, 'attendance_date' => $this->day->date, ...$fields]);
    }

    public function test_badge_print_times_become_sign_ins_for_the_day(): void
    {
        [$outOnly, $noScan, $earlier, $unprinted, $facilitator] = [$this->person(), $this->person(), $this->person(), $this->person(), $this->person('facilitator')];
        $this->printedAt($outOnly, '2026-11-02 08:40'); $this->record($outOnly, ['checked_out_at' => Carbon::parse('2026-11-02 16:10', 'Africa/Lagos'), 'status' => 'pending']);
        $this->printedAt($noScan, '2026-11-02 09:05');
        $this->printedAt($earlier, '2026-11-02 10:00'); $this->record($earlier, ['checked_in_at' => Carbon::parse('2026-11-02 08:15', 'Africa/Lagos'), 'status' => 'present', 'signin_method' => 'scanner']);
        $this->printedAt($unprinted, null);
        $this->printedAt($facilitator, '2026-11-02 08:50');

        $this->artisan('attendance:signin-from-badges', ['date' => '2026-11-02', '--dry-run' => true])->expectsOutputToContain('Would give 1 existing records')->assertSuccessful();
        $this->assertNull(DailyAttendance::where('user_id', $outOnly->id)->value('checked_in_at'));
        $this->artisan('attendance:signin-from-badges', ['date' => '2026-11-02'])->expectsOutputToContain('created 1 records')->assertSuccessful();

        $record = DailyAttendance::where('user_id', $outOnly->id)->sole();
        $this->assertSame(['08:40', 'badge_print', 'present', 27000], [$record->checked_in_at->timezone('Africa/Lagos')->format('H:i'), $record->signin_method, $record->status, $record->duration_seconds]);
        $this->assertSame('09:05', DailyAttendance::where('user_id', $noScan->id)->sole()->checked_in_at->timezone('Africa/Lagos')->format('H:i'));
        $this->assertSame('08:15', DailyAttendance::where('user_id', $earlier->id)->sole()->checked_in_at->timezone('Africa/Lagos')->format('H:i'));
        $this->assertSame(0, DailyAttendance::whereIn('user_id', [$unprinted->id, $facilitator->id])->count());
        $this->assertGreaterThan((int) $record->synced_version, $record->sync_version);
    }

    public function test_printed_badges_are_marked_collected_and_the_print_station_hands_them_over(): void
    {
        [$a, $b] = [$this->person(), $this->person()];
        $printed = $this->printedAt($a, '2026-11-02 08:40');
        $notPrinted = $this->printedAt($b, null);
        $this->artisan('badges:collected-when-printed')->expectsOutputToContain('Marked 1 printed badges')->assertSuccessful();
        $this->assertEquals($printed->fresh()->printed_at, $printed->fresh()->collected_at);
        $this->assertNull($notPrinted->fresh()->collected_at);

        $this->actingAs($this->admin)->postJson('/badges/print-station', ['code' => $b->printCode()])->assertOk();
        $this->assertNotNull($notPrinted->fresh()->printed_at);
        $this->assertEquals($notPrinted->fresh()->printed_at, $notPrinted->fresh()->collected_at);
    }
}
