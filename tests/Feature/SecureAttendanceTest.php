<?php

namespace Tests\Feature;

use App\Models\AttendanceChallenge;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceRequest;
use App\Models\DailyAttendance;
use App\Models\Programme;
use App\Models\ProgrammeDay;
use App\Models\User;
use App\Services\AttendanceSecurity;
use App\Services\AttendanceService;
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AttendanceAuthenticator;
use Tests\TestCase;

class SecureAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private ProgrammeDay $day;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        config(['attendance.origin' => 'https://orientation.example.test', 'attendance.rp_id' => 'orientation.example.test']);
        $this->staff = $this->user();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'Africa/Lagos'));
        $this->day = ProgrammeDay::where('number', 1)->firstOrFail();
        $this->day->update(['attendance_required' => true, 'checkin_opens' => '08:00', 'checkin_closes' => '10:00', 'late_after' => '08:30', 'checkout_opens' => '09:00', 'checkout_closes' => '18:00']);
        $p = Programme::first();
        $s = $p->settings;
        $s['attendance_security'] = ['venue_name' => 'Test venue', 'latitude' => 9.0765, 'longitude' => 7.3986, 'radius' => 200, 'max_accuracy' => 30, 'max_age_seconds' => 45, 'location_retention_days' => 90, 'audit_retention_days' => 365];
        $p->update(['settings' => $s]);
        $this->withCredentials();
        $this->actingAs($this->staff);
    }

    private function user(string $role = 'staff', array $permissions = []): User
    {
        $u = User::factory()->create(['password' => bcrypt('AttendancePassword123')]);
        $u->forceFill(['role' => $role, 'active' => true, 'permissions' => $permissions])->save();
        $u->programmes()->attach(Programme::first()->id);

        return $u;
    }

    private function location(array $overrides = []): array
    {
        return array_replace(['latitude' => 9.0765, 'longitude' => 7.3986, 'accuracy' => 5, 'timestamp' => now()->getTimestampMs()], $overrides);
    }

    private function ceremonyOptions(string $purpose): array
    {
        $response = $this->postJson('/attendance/verification/'.$purpose.'/options', $purpose === 'register' ? ['password' => 'AttendancePassword123'] : ['location' => $this->location()])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());

        return $response->json();
    }

    private function enroll(?AttendanceAuthenticator $a = null): AttendanceAuthenticator
    {
        $a ??= new AttendanceAuthenticator;
        $v = $a->register($this->ceremonyOptions('register'));
        $res = $this->postJson('/attendance/verification/register/verify', $v);
        $this->assertSame(200, $res->status(), $res->json('message') ?? 'Registration failed.');

        return $a;
    }

    private function verify(AttendanceAuthenticator $a, string $purpose, int $counter = 1, array $changes = [])
    {
        $payload = $a->assertion($this->ceremonyOptions($purpose), $counter);

        return $this->postJson('/attendance/verification/'.$purpose.'/verify', array_replace($payload, ['location' => $this->location()], $changes));
    }

    public function test_real_registration_signin_checkout_and_daily_uniqueness(): void
    {
        $a = $this->enroll();
        $this->verify($a, 'in')->assertOk();
        $this->postJson('/attendance/verification/in/options', ['location' => $this->location()])->assertStatus(409);
        $this->travelTo(now()->addHour());
        $this->verify($a, 'out', 2)->assertOk();
        $this->postJson('/attendance/verification/out/options', ['location' => $this->location()])->assertStatus(409);
        $r = DailyAttendance::firstOrFail();
        $this->assertSame(3600, $r->duration_seconds);
        $this->assertSame('Late', $r->punctuality_status);
        $this->assertTrue($r->signin_device_verified && $r->signout_location_verified);
        $this->assertSame(9.0765, $r->signin_location['latitude']);
        $this->assertStringNotContainsString('latitude', DB::table('daily_attendance')->value('signin_location'));
        $this->assertArrayNotHasKey('signin_location', $r->toArray());
        $this->assertDatabaseCount('daily_attendance', 1);
        $this->assertDatabaseHas('attendance_audit_logs', ['event' => 'attendance.in', 'verified' => true]);
    }

    public function test_password_and_legacy_endpoints_cannot_mark_attendance(): void
    {
        $this->postJson('/attendance/verification/in/options', ['location' => $this->location()])->assertStatus(422);
        $this->post('/manage/attendance', [])->assertNotFound();
        $this->post('/daily/checkin/'.$this->day->id, ['mode' => 'in'])->assertNotFound();
        $this->postJson('/attendance/verification/register/options', ['password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseCount('daily_attendance', 0);
    }

    public function test_synced_registration_is_rejected_and_challenge_consumed(): void
    {
        $a = new AttendanceAuthenticator;
        $v = $a->register($this->ceremonyOptions('register'), 0x4D);
        $this->postJson('/attendance/verification/register/verify', $v)->assertUnprocessable()->assertSee('synchronized');
        $this->postJson('/attendance/verification/register/verify', $v)->assertUnprocessable();
        $this->assertDatabaseCount('attendance_devices', 0);
    }

    public function test_wrong_signature_missing_uv_backup_origin_rp_and_counter_fail(): void
    {
        $a = $this->enroll();
        foreach (['signature', 'uv', 'backup', 'origin', 'rp', 'challenge'] as $case) {
            $options = $this->ceremonyOptions('in');
            $payload = $a->assertion($options, 1, $case === 'uv' ? 1 : ($case === 'backup' ? 13 : 5), $case === 'origin' ? 'https://evil.example.test' : 'https://orientation.example.test', $case === 'rp' ? 'evil.example.test' : 'orientation.example.test');
            if ($case === 'signature') {
                $payload['signature'] = AttendanceSecurity::encode(random_bytes(64));
            }
            if ($case === 'challenge') {
                $options['options']['publicKey']['challenge'] = AttendanceSecurity::encode(random_bytes(32));
                $payload = $a->assertion($options);
            }
            $this->postJson('/attendance/verification/in/verify', [...$payload, 'location' => $this->location()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('daily_attendance', 0);
        $this->verify($a, 'in', 2)->assertOk();
        $this->verify($a, 'out', 2)->assertUnprocessable();
        $this->verify($a, 'out', 3)->assertOk();
    }

    public function test_replay_expiry_wrong_session_and_cross_account_are_rejected(): void
    {
        $a = $this->enroll();
        $v = $a->assertion($this->ceremonyOptions('in'));
        $other = $this->user();
        $this->actingAs($other)->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertUnprocessable();
        $this->withCredentials();
        $this->actingAs($this->staff);
        AttendanceChallenge::whereKey($v['challenge_id'])->update(['session_hash' => str_repeat('a', 64)]);
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertUnprocessable();
        $v = $a->assertion($this->ceremonyOptions('in'));
        $this->travelTo(now()->addSeconds(121));
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertUnprocessable();
        $v = $a->assertion($this->ceremonyOptions('in'));
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertOk();
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertUnprocessable();
        $this->assertDatabaseCount('daily_attendance', 1);
    }

    public function test_another_accounts_phone_cannot_authenticate_even_with_its_valid_signature(): void
    {
        $a = $this->enroll();
        $other = $this->user();
        $this->actingAs($other);
        $b = $this->enroll();
        $this->withCredentials();
        $this->actingAs($this->staff);
        $v = $b->assertion($this->ceremonyOptions('in'));
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertForbidden();
        $this->assertDatabaseCount('daily_attendance', 0);
    }

    public function test_location_is_mandatory_accurate_fresh_and_checked_again_after_authentication(): void
    {
        $a = $this->enroll();
        foreach ([[], $this->location(['latitude' => 0]), $this->location(['accuracy' => 100]), $this->location(['timestamp' => now()->subMinute()->getTimestampMs()]), $this->location(['timestamp' => now()->addMinute()->getTimestampMs()])] as $location) {
            $this->postJson('/attendance/verification/in/options', ['location' => $location])->assertUnprocessable();
        }
        $this->postJson('/attendance/verification-failure', ['reason' => 'location_denied', 'purpose' => 'in'])->assertNoContent();
        $this->assertDatabaseHas('attendance_audit_logs', ['event' => 'location.failed', 'reason' => 'location_denied']);
        $v = $a->assertion($this->ceremonyOptions('in'));
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location(['longitude' => 0])])->assertUnprocessable();
        $this->assertDatabaseCount('daily_attendance', 0);
    }

    public function test_replacement_is_approved_only_by_attendance_manager_revokes_old_and_creates_no_attendance(): void
    {
        $a = $this->enroll();
        $pending = $a->assertion($this->ceremonyOptions('in'));
        $this->post('/attendance/device/replacement', ['reason' => 'My phone has been damaged'])->assertRedirect();
        $row = AttendanceDeviceRequest::firstOrFail();
        $this->post('/attendance/device/replacement/'.$row->id, ['decision' => 'approved', 'decision_reason' => 'Replacement identity reviewed'])->assertForbidden();
        $unauthorized = $this->user('supervisor');
        $this->actingAs($unauthorized)->post('/attendance/device/replacement/'.$row->id, ['decision' => 'approved', 'decision_reason' => 'Replacement identity reviewed'])->assertForbidden();
        $manager = $this->user('supervisor', ['attendance']);
        $this->actingAs($manager)->post('/attendance/device/replacement/'.$row->id, ['decision' => 'approved', 'decision_reason' => 'Replacement identity reviewed'])->assertRedirect();
        $this->assertNotNull(AttendanceDevice::first()->revoked_at);
        $this->assertSame($manager->id, $row->fresh()->approved_by);
        $this->assertDatabaseCount('daily_attendance', 0);
        $this->actingAs($this->staff)->postJson('/attendance/verification/in/verify', [...$pending, 'location' => $this->location()])->assertUnprocessable();
        $b = $this->enroll();
        $this->assertSame(1, AttendanceDevice::where('active_user_id', $this->staff->id)->count());
        $this->postJson('/attendance/verification/register/options', ['password' => 'AttendancePassword123'])->assertStatus(409);
        $this->verify($b, 'in')->assertOk();
    }

    public function test_policy_dates_windows_and_signout_before_signin(): void
    {
        $this->enroll();
        $this->postJson('/attendance/verification/out/options', ['location' => $this->location()])->assertUnprocessable();
        $this->day->update(['cancelled' => true]);
        $this->postJson('/attendance/verification/in/options', ['location' => $this->location()])->assertUnprocessable();
        $this->day->update(['cancelled' => false, 'checkin_closes' => '08:59']);
        $this->postJson('/attendance/verification/in/options', ['location' => $this->location()])->assertUnprocessable();
        $this->day->update(['checkin_closes' => '10:00']);
        $v = (new AttendanceAuthenticator)->assertion($this->ceremonyOptions('in'));
        $this->day->update(['cancelled' => true]);
        $this->postJson('/attendance/verification/in/verify', [...$v, 'location' => $this->location()])->assertUnprocessable();
        $this->assertDatabaseCount('daily_attendance', 0);
    }

    public function test_qr_is_permanent_dashboard_reports_and_permissions(): void
    {
        $a = $this->enroll();
        $this->verify($a, 'in')->assertOk();
        $manager = $this->user('supervisor', ['attendance']);
        $this->actingAs($manager);
        $qr = $this->get('/attendance/qr')->assertOk()->assertSee('https://orientation.example.test/attendance/sign-in')->getContent();
        $this->travelTo(now()->addMinutes(6));
        $this->assertSame($qr, $this->get('/attendance/qr')->getContent());
        $this->get('/attendance/qr/in.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/attendance/live?name='.urlencode($this->staff->name))->assertOk()->assertSee('Currently signed in')->assertSee('Verified');
        $this->get('/attendance/security/audit')->assertOk();
        foreach (['daily', 'individual', 'department', 'late', 'incomplete', 'summary'] as $mode) {
            $this->get('/reports/export?report=attendance&attendance_mode='.$mode.'&format=csv')->assertOk();
        }
        $this->get('/reports/export?report=attendance&format=xlsx')->assertOk();
        $this->get('/reports/export?report=staff')->assertForbidden();
        $this->actingAs($this->staff)->get('/attendance/security/audit')->assertForbidden();
        $this->get('/attendance/qr')->assertForbidden();
    }

    public function test_on_time_summary_reports_and_future_absences_are_calculated(): void
    {
        $this->day->update(['late_after' => '09:30']);
        $this->staff->forceFill(['department' => 'ICT', 'staff_number' => 'ICRC-TEST-001'])->save();
        $a = $this->enroll();
        $this->verify($a, 'in')->assertOk();
        $this->assertSame('On Time', DailyAttendance::first()->punctuality_status);
        $this->travelTo(now()->setTime(11, 0));
        $summary = app(AttendanceService::class)->summary($this->staff);
        $this->assertSame(1, $summary['denominator']);
        $this->assertSame(100.0, $summary['percentage']);
        $this->assertSame(0, $summary['absent']);
        $this->actingAs($this->user('supervisor', ['attendance']));
        $response = $this->get('/reports/export?report=attendance&attendance_mode=summary&format=csv')->assertOk();
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $row = str_getcsv(end($lines));
        $this->assertSame(['ICRC-TEST-001', $this->staff->name, 'ICT', '1', '1', '0', '0', '100'], $row);
        $csv = $this->get('/reports/export?report=attendance&attendance_mode=late&format=csv')->streamedContent();
        $this->assertStringNotContainsString($this->staff->name, $csv);
        $csv = $this->get('/reports/export?report=attendance&attendance_mode=incomplete&format=csv')->streamedContent();
        $this->assertStringContainsString($this->staff->name, $csv);
        $this->assertStringNotContainsString('latitude', $csv);
        $this->get('/attendance/live')->assertOk()->assertSee('100%')->assertSee('On time');
    }

    public function test_attendance_policy_is_authorized_validated_and_no_historical_data_is_rewritten(): void
    {
        $values = ['venue_name' => 'Approved venue', 'latitude' => 9, 'longitude' => 7, 'radius' => 200, 'max_accuracy' => 20, 'max_age_seconds' => 45, 'location_retention_days' => 90, 'audit_retention_days' => 365, 'starts_on' => '2026-11-02', 'ends_on' => '2026-12-11'];
        $this->post('/attendance/security/settings', $values)->assertForbidden();
        $this->actingAs($this->user('supervisor', ['attendance']));
        $this->postJson('/attendance/security/settings', [...$values, 'max_accuracy' => 300])->assertUnprocessable();
        $this->post('/attendance/security/settings', $values)->assertRedirect();
        $this->assertSame('Approved venue', Programme::first()->settings['attendance_security']['venue_name']);
        $historic = DailyAttendance::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $this->staff->id, 'status' => 'present', 'notes' => 'Historical record']);
        $this->assertFalse($historic->fresh()->signin_device_verified);
        $this->get('/attendance?staff='.$this->staff->id)->assertOk()->assertSee('Historical record');
        $this->assertDatabaseCount('daily_attendance', 1);
        $this->expectException(UniqueConstraintViolationException::class);
        DailyAttendance::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $this->staff->id, 'status' => 'present']);
    }
}
