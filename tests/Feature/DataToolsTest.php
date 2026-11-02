<?php

namespace Tests\Feature;

use App\Models\{Badge, DailyAttendance, Programme, ProgrammeDay, User};
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        $this->travelTo(Carbon::parse('2026-11-02 18:00:00', 'Africa/Lagos'));
        $this->programme = Programme::first();
        $this->admin = $this->person('admin');
    }

    private function person(string $role = 'staff', array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, ...$extra]);
        $user->programmes()->attach($this->programme->id);

        return $user;
    }

    public function test_admins_run_the_attendance_and_badge_jobs_from_settings(): void
    {
        $staff = $this->person();
        $day = ProgrammeDay::where('number', 1)->firstOrFail();
        Badge::create(['programme_id' => $this->programme->id, 'subject_key' => 'u:'.$staff->id, 'user_id' => $staff->id, 'category' => 'staff', 'snapshot' => ['name' => $staff->name],
            'generated_at' => now(), 'generated_by' => $this->admin->id, 'printed_at' => Carbon::parse('2026-11-02 08:30', 'Africa/Lagos')]);
        DailyAttendance::create(['programme_id' => $this->programme->id, 'programme_day_id' => $day->id, 'user_id' => $staff->id, 'staff_name' => $staff->name, 'attendance_date' => $day->date, 'checked_out_at' => now(), 'status' => 'pending']);

        $this->actingAs($this->admin)->get('/settings?tab=tools')->assertOk()->assertSee('Data tools')->assertSee('The database is up to date.')
            ->assertSee('Take attendance all day on every programme day')->assertSee('1 record gets a sign-in from the badge print time')->assertSee('Mark 1 printed badge as collected');

        $this->post('/settings/tools/attendance-days')->assertRedirect('/settings?tab=tools');
        $this->assertSame(0, ProgrammeDay::where('cancelled', false)->where('attendance_required', false)->count());
        $this->post('/settings/tools/signins', ['date' => '2026-11-02'])->assertSessionHas('success', fn ($m) => str_contains($m, '1 records got their sign-in'));
        $this->assertSame('08:30', DailyAttendance::sole()->checked_in_at->timezone('Africa/Lagos')->format('H:i'));
        $this->post('/settings/tools/badges-collected')->assertSessionHas('success', '1 printed badges are now marked as collected at their print time.');
        $this->assertNotNull(Badge::sole()->collected_at);
        $this->post('/settings/tools/database')->assertSessionHas('success', 'The database is already up to date.');
        $this->get('/settings?tab=tools')->assertSee('Every programme day already takes attendance all day.')->assertSee('Every printed badge is marked collected.');

        $this->actingAs($this->person('facilitator', ['permissions' => ['attendance', 'staff']]))->post('/settings/tools/attendance-days')->assertForbidden();
        config(['attendance_sync.url' => 'https://live.example.test', 'attendance_sync.origin' => 'venue']);
        $this->actingAs($this->admin)->post('/settings/tools/badges-collected')->assertStatus(422);
        $this->post('/settings/tools/database')->assertRedirect('/settings?tab=tools');
    }

    public function test_admins_can_point_an_account_at_the_badge_qr_id_the_person_is_wearing(): void
    {
        $staff = $this->person('facilitator', ['name' => 'Rahinah Sirika', 'email' => 'rahinah@example.test']);
        $other = $this->person();
        $form = ['first_name' => 'Rahinah', 'surname' => 'Sirika', 'email' => 'rahinah@example.test', 'role' => 'facilitator', 'active' => 1];
        $this->actingAs($this->admin)->get('/manage/staff/'.$staff->slug)->assertSee('Badge QR ID')->assertSee($staff->qr_code);

        $this->post('/manage/staff/'.$staff->slug, [...$form, 'qr_code' => $other->qr_code])->assertSessionHasErrors('qr_code');
        $this->post('/manage/staff/'.$staff->slug, [...$form, 'qr_code' => '12345'])->assertSessionHasErrors('qr_code');
        $this->post('/manage/staff/'.$staff->slug, [...$form, 'qr_code' => '3828126773312866'])->assertSessionHasNoErrors();
        $this->assertSame('3828126773312866', $staff->fresh()->qr_code);

        $manager = $this->person('facilitator', ['permissions' => ['staff']]);
        $this->actingAs($manager)->get('/manage/staff/'.$other->slug)->assertDontSee('Badge QR ID');
        $before = $other->qr_code;
        $this->post('/manage/staff/'.$other->slug, ['first_name' => 'A', 'surname' => 'B', 'email' => $other->email, 'role' => 'staff', 'active' => 1, 'qr_code' => '1111222233334444']);
        $this->assertSame($before, $other->fresh()->qr_code);
    }
}
