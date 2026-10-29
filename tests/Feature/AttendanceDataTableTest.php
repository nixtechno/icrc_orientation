<?php

namespace Tests\Feature;

use App\Models\{DailyAttendance, Programme, ProgrammeDay, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceDataTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
    }

    public function test_live_table_paginates_filters_and_sorts_without_reducing_summary_totals(): void
    {
        $programme = Programme::first();
        $day = ProgrammeDay::orderBy('date')->firstOrFail(); $day->update(['attendance_required' => true]);
        $this->travelTo($day->date->copy()->setTime(12, 0));
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        for ($i=1; $i<=25; $i++) {
            $staff = User::factory()->create(['role' => 'staff', 'active' => true, 'name' => sprintf('Staff %02d', $i)]);
            $staff->forceFill(['department' => $i===25?'Training':'ICT', 'staff_number' => 'TEST-'.$i])->save();
            $staff->programmes()->attach($programme->id);
            if ($i>=24) DailyAttendance::create(['programme_id' => $programme->id, 'programme_day_id' => $day->id, 'user_id' => $staff->id, 'status' => 'late', 'checked_in_at' => now()->subHour()]);
        }
        $this->actingAs($admin)->get('/attendance/live')->assertOk()->assertSee('Staff 01')->assertDontSee('Staff 11')->assertSee('of 25 staff')->assertViewHas('staff', fn ($rows) => $rows->count()===10 && $rows->total()===25)->assertViewHas('counts', fn ($counts) => $counts['Expected']===25 && $counts['Late']===2);
        $this->get('/attendance/live?attendance_page=2')->assertOk()->assertSee('Staff 11')->assertDontSee('Staff 01')->assertSee('Page 2 of 3');
        $this->get('/attendance/live?attendance_page=99')->assertOk()->assertSee('Staff 25')->assertSee('Page 3 of 3');
        $this->get('/attendance/live?per_page=25')->assertOk()->assertSee('Staff 01')->assertSee('Staff 25')->assertSee('Page 1 of 1');
        $this->get('/attendance/live?status=late')->assertOk()->assertSee('Staff 24')->assertSee('Staff 25')->assertDontSee('Staff 01')->assertViewHas('staff', fn ($rows) => $rows->total()===2)->assertViewHas('counts', fn ($counts) => $counts['Expected']===25);
        $this->get('/attendance/live?status=pending')->assertViewHas('staff', fn ($rows) => $rows->total()===23);
        $this->get('/attendance/live?name=TEST-25')->assertOk()->assertSee('Staff 25')->assertDontSee('Staff 01');
        $this->get('/attendance/live?name=Training')->assertViewHas('staff', fn ($rows) => $rows->total()===1);
        $this->get('/attendance/live?sort=name&direction=desc')->assertSeeInOrder(['Staff 25','Staff 24','Staff 23']);
        $this->get('/attendance/live?name=Nobody')->assertOk()->assertSee('No staff match these filters.')->assertSee('of 0 staff');
        $this->get('/dashboard')->assertOk()->assertSee('Rows per page')->assertSee('data-live-filter', false);
    }

    public function test_table_parameters_are_validated_and_staff_cannot_access_management_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin);
        foreach (['per_page=10000','sort=password','direction=sideways','attendance_page=-1'] as $query) $this->getJson('/attendance/live?'.$query)->assertUnprocessable();
        $staff = User::factory()->create(['role' => 'staff', 'active' => true]); $staff->programmes()->attach(Programme::first()->id);
        $this->actingAs($staff)->get('/attendance/live')->assertForbidden();
    }
}
