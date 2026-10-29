<?php

namespace Tests\Feature;

use App\Models\{Activity, DailyAttendance, Programme, Submission, User};
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail, Notification, Storage};
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_is_repeatable_has_working_role_accounts_and_realistic_private_data(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 17:00:00', 'Africa/Lagos'));
        Storage::fake('local'); Mail::fake(); Notification::fake();
        $seeder = app(DemoSeeder::class); $seeder->staffCount = 20; $seeder->run();
        $counts = [User::count(), Activity::count(), Submission::count(), DailyAttendance::count()];
        $start = Programme::first()->starts_on->toDateString();
        $seeder->run();
        $this->assertSame($counts, [User::count(), Activity::count(), Submission::count(), DailyAttendance::count()]);
        $this->assertSame(24, User::count());
        $this->assertSame(12, Activity::count());
        $this->assertGreaterThan(100, DailyAttendance::count());
        $this->assertTrue(Programme::first()->settings['demo_mode']);
        $this->assertSame($start, Programme::first()->starts_on->toDateString());
        $this->assertSame(0, DailyAttendance::where('signin_device_verified', true)->orWhere('signin_location_verified', true)->count());
        $this->assertSame(0, DailyAttendance::where('checked_in_at', '>', now())->count());
        $admin = User::where('email', 'admin@icrc-demo.test')->firstOrFail();
        $staff = User::where('email', 'staff0001@icrc-demo.test')->firstOrFail();
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $admin->password));
        $this->assertNotNull(app(AttendanceService::class)->summary($staff)['percentage']);
        foreach ([$admin, User::where('email', 'facilitator@icrc-demo.test')->first(), $staff] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk();
            $this->get('/activities')->assertOk();
            $this->get('/attendance')->assertOk();
            $this->get('/resources')->assertOk();
            $this->get('/profile')->assertOk()->assertSee('Personal &amp; contact details', false);
        }
        $this->actingAs($admin)->get('/badges')->assertOk();
        $this->get('/reports/export?report=attendance')->assertOk();
        $this->get('/manage/supplement')->assertOk();
        $resource = DB::table('resources')->where('published', true)->first();
        $this->actingAs($staff)->get('/resources/'.$resource->id.'/download')->assertOk()->assertDownload('DEMO-PPP-guide.pdf');
        $quiz = Activity::where('kind', 'quiz')->firstOrFail();
        $this->get('/activities/'.$quiz->id)->assertOk();
        $this->post('/activities/'.$quiz->id.'/start')->assertRedirect();
        Mail::assertNothingSent(); Notification::assertNothingSent();
        $seeder->staffCount = 30; $seeder->run();
        $this->assertSame(34, User::count());
    }

    public function test_demo_seed_refuses_a_database_containing_real_accounts(): void
    {
        User::factory()->create(['email' => 'real-person@example.test']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains non-demo accounts');
        app(DemoSeeder::class)->run();
    }

    public function test_demo_seed_refuses_the_main_database_before_writing_any_records(): void
    {
        $connection = DB::connection();
        $original = $connection->getDatabaseName();
        $connection->setDatabaseName('icrc_orientation');
        try {
            app(DemoSeeder::class)->run();
            $this->fail('Main database should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('allowed only', $e->getMessage());
        } finally {
            $connection->setDatabaseName($original);
        }
        $this->assertSame(0, User::count());
    }
}
