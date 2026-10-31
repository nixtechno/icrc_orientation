<?php

namespace Tests\Feature;

use App\Models\{Badge, DailyAttendance, Programme, ProgrammeDay, User};
use App\Services\{AttendanceSync, TimetableImporter};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-sync-token-0123456789abcdef0123456789';

    private User $admin;
    private User $staff;
    private ProgrammeDay $day;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        config(['attendance_sync.token' => self::TOKEN, 'attendance_sync.url' => null, 'attendance_sync.origin' => 'online']);
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'Africa/Lagos'));
        $this->admin = $this->user('admin', ['email' => 'operator@example.test']);
        $this->staff = $this->user('staff', ['name' => 'Amina Bello', 'email' => 'amina@example.test']);
        $this->day = ProgrammeDay::where('number', 1)->firstOrFail();
        $this->day->update(['attendance_required' => true, 'cancelled' => false, 'checkin_opens' => '08:00', 'checkin_closes' => '10:00', 'late_after' => '08:30', 'checkout_opens' => '09:00', 'checkout_closes' => '18:00']);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, ...$attributes]);
        $user->programmes()->attach(Programme::first()->id);

        return $user;
    }

    private function venueRecord(array $overrides = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'origin' => 'venue', 'email' => 'amina@example.test', 'qr_code' => $this->staff->qr_code,
            'date' => '2026-11-02', 'staff_name' => 'Amina Bello', 'status' => 'present',
            'checked_in_at' => '2026-11-02T08:10:00+01:00', 'checked_out_at' => null,
            'signin_method' => 'scanner', 'signin_scanned_by' => 'operator@example.test', 'signin_device_verified' => false, 'signin_location_verified' => false,
            'signout_method' => null, 'signout_scanned_by' => null, 'signout_device_verified' => false, 'signout_location_verified' => false,
            ...$overrides,
        ];
    }

    private function upload(array $records, array $logs = [], string $token = self::TOKEN)
    {
        return $this->withToken($token)->postJson('/api/attendance-sync/upload', ['origin' => 'venue', 'records' => $records, 'logs' => $logs]);
    }

    private function asVenue(): void
    {
        config(['attendance_sync.url' => 'https://live.example.test', 'attendance_sync.origin' => 'venue']);
    }

    private function emptySnapshot(array $extra = []): array
    {
        return ['cursor' => now()->toIso8601String(), 'programme' => ['starts_on' => '2026-11-02', 'ends_on' => '2026-12-11'], 'days' => [], 'people' => [], 'badges' => [], 'attendance' => [], ...$extra];
    }

    public function test_the_live_site_only_answers_requests_with_the_sync_token(): void
    {
        $this->getJson('/api/attendance-sync/ping')->assertUnauthorized();
        $this->withToken('wrong-token-wrong-token-wrong-token-xx')->getJson('/api/attendance-sync/snapshot')->assertUnauthorized();
        $this->upload([$this->venueRecord()], [], 'nope')->assertUnauthorized();
        config(['attendance_sync.token' => 'short']);
        $this->withToken('short')->getJson('/api/attendance-sync/ping')->assertUnauthorized();
        config(['attendance_sync.token' => self::TOKEN]);
        $this->withToken(self::TOKEN)->getJson('/api/attendance-sync/ping')->assertOk()->assertJsonStructure(['time']);
        $this->assertDatabaseCount('daily_attendance', 0);
    }

    public function test_venue_scans_merge_into_the_live_site_and_resending_changes_nothing(): void
    {
        $record = $this->venueRecord();
        $this->upload([$record], [[
            'uuid' => $log = (string) Str::uuid(), 'user' => 'amina@example.test', 'actor' => 'operator@example.test',
            'event' => 'attendance.scanner.in', 'verified' => true, 'reason' => 'Staff badge scanned.', 'created_at' => '2026-11-02T08:10:00+01:00',
        ]])->assertOk()->assertJsonPath('records.'.$record['uuid'], null)->assertJsonPath('logs.0', $log);
        $saved = DailyAttendance::firstOrFail();
        $this->assertSame('present', $saved->status);
        $this->assertSame('08:10:00', $saved->checked_in_at->format('H:i:s'));
        $this->assertEquals($this->admin->id, $saved->signin_scanned_by);
        $this->assertSame([$record['uuid'], 'venue'], [$saved->sync_uuid, $saved->origin]);

        $this->upload([$record, $this->venueRecord(['uuid' => $record['uuid'], 'checked_out_at' => '2026-11-02T17:05:00+01:00', 'signout_method' => 'scanner'])])->assertOk();
        $saved->refresh();
        $this->assertSame('17:05:00', $saved->checked_out_at->format('H:i:s'));
        $this->assertSame(8 * 3600 + 55 * 60, $saved->duration_seconds);
        $this->upload([$record], [['uuid' => $log, 'event' => 'attendance.scanner.in', 'verified' => true, 'created_at' => '2026-11-02T08:10:00+01:00']])->assertOk();
        $this->assertDatabaseCount('daily_attendance', 1);
        $this->assertSame(1, DB::table('attendance_audit_logs')->where('sync_uuid', $log)->count());
        $this->assertSame('venue', Cache::get(AttendanceSync::RECEIVED)['origin']);
    }

    public function test_merge_keeps_the_earliest_sign_in_latest_sign_out_and_device_verified_times(): void
    {
        $phone = DailyAttendance::create([
            'programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $this->staff->id,
            'staff_name' => 'Amina Bello', 'attendance_date' => $this->day->date, 'status' => 'late', 'punctuality_status' => 'Late',
            'checked_in_at' => Carbon::parse('2026-11-02 08:40:00', 'Africa/Lagos'), 'signin_method' => 'device', 'signin_device_verified' => true,
            'checked_out_at' => Carbon::parse('2026-11-02 16:00:00', 'Africa/Lagos'), 'signout_method' => 'scanner',
        ]);
        // An earlier scan does not replace a phone sign-in checked by device, but a later sign-out scan wins.
        $this->upload([$this->venueRecord(['checked_in_at' => '2026-11-02T08:05:00+01:00', 'checked_out_at' => '2026-11-02T17:30:00+01:00', 'signout_method' => 'scanner'])])->assertOk();
        $phone->refresh();
        $this->assertSame(['08:40:00', 'device', 'late'], [$phone->checked_in_at->format('H:i:s'), $phone->signin_method, $phone->status]);
        $this->assertSame('17:30:00', $phone->checked_out_at->format('H:i:s'));
        // An earlier sign-out scan does not replace a later one.
        $this->upload([$this->venueRecord(['checked_out_at' => '2026-11-02T12:00:00+01:00'])])->assertOk();
        $this->assertSame('17:30:00', $phone->fresh()->checked_out_at->format('H:i:s'));

        $other = $this->user('staff', ['email' => 'chidi@example.test']);
        $this->upload([$this->venueRecord(['email' => 'chidi@example.test', 'qr_code' => $other->qr_code, 'checked_in_at' => '2026-11-02T08:45:00+01:00'])]);
        $this->upload([$this->venueRecord(['email' => 'chidi@example.test', 'qr_code' => $other->qr_code, 'checked_in_at' => '2026-11-02T08:20:00+01:00'])]);
        $record = DailyAttendance::where('user_id', $other->id)->firstOrFail();
        $this->assertSame(['08:20:00', 'present', 'On Time'], [$record->checked_in_at->format('H:i:s'), $record->status, $record->punctuality_status]);
    }

    public function test_records_the_live_site_cannot_place_are_reported_back(): void
    {
        $unknown = $this->venueRecord(['email' => 'nobody@example.test', 'qr_code' => '1111111111111111']);
        $noDay = $this->venueRecord(['date' => '2030-01-01']);
        $this->upload([$unknown, $noDay])->assertOk()
            ->assertJsonPath('records.'.$unknown['uuid'], 'This person (nobody@example.test) is not on the live site.')
            ->assertJsonPath('records.'.$noDay['uuid'], 'There is no programme day on 2030-01-01.');
        $this->assertDatabaseCount('daily_attendance', 0);
        $this->assertSame(2, Cache::get(AttendanceSync::RECEIVED)['problems']);
    }

    public function test_the_snapshot_carries_people_badges_windows_and_attendance_changed_since_the_cursor(): void
    {
        Badge::create(['programme_id' => Programme::first()->id, 'subject_key' => 'u:'.$this->staff->id, 'user_id' => $this->staff->id, 'category' => 'staff', 'snapshot' => ['name' => 'Amina Bello'], 'generated_at' => now(), 'generated_by' => $this->admin->id]);
        $this->upload([$this->venueRecord()]);
        $this->travel(1)->minutes();
        $snapshot = $this->withToken(self::TOKEN)->getJson('/api/attendance-sync/snapshot')->assertOk()->json();
        $amina = collect($snapshot['people'])->firstWhere('email', 'amina@example.test');
        $this->assertSame([$this->staff->qr_code, $this->staff->print_code, true], [$amina['qr_code'], $amina['print_code'], $amina['enrolled']]);
        $this->assertArrayNotHasKey('password', $amina);
        $this->assertSame('08:30', substr(collect($snapshot['days'])->firstWhere('date', '2026-11-02')['late_after'], 0, 5));
        $this->assertSame('amina@example.test', $snapshot['badges'][0]['email']);
        $this->assertCount(1, $snapshot['attendance']);

        $this->travel(5)->minutes();
        $later = $this->withToken(self::TOKEN)->getJson('/api/attendance-sync/snapshot?since='.urlencode($snapshot['cursor']))->json();
        $this->assertCount(0, $later['attendance']);
    }

    public function test_venue_uploads_waiting_scans_and_keeps_changes_made_during_the_upload_waiting(): void
    {
        $this->asVenue();
        $record = DailyAttendance::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $this->staff->id, 'staff_name' => 'Amina Bello', 'attendance_date' => $this->day->date, 'status' => 'present', 'checked_in_at' => now(), 'signin_method' => 'scanner', 'signin_scanned_by' => $this->admin->id]);
        DB::table('attendance_audit_logs')->insert(['programme_id' => Programme::first()->id, 'user_id' => $this->staff->id, 'actor_id' => $this->admin->id, 'event' => 'attendance.scanner.in', 'verified' => true, 'created_at' => now(), 'sync_uuid' => (string) Str::uuid(), 'origin' => 'venue']);
        $this->assertSame(['venue', 1], [$record->origin, $record->sync_version]);
        $sent = [];
        Http::fake(function (ClientRequest $request) use (&$sent, $record) {
            $this->assertSame('Bearer '.self::TOKEN, $request->header('Authorization')[0]);
            return match (true) {
                str_ends_with($request->url(), '/ping') => Http::response(['time' => now()->toIso8601String()]),
                str_contains($request->url(), '/upload') => (function () use ($request, &$sent, $record) {
                    $sent[] = $request->data();
                    if ($request['records']) {
                        // A sign-out scanned while the upload is in flight.
                        DailyAttendance::find($record->id)->update(['checked_out_at' => now()->addHours(8)]);
                    }
                    return Http::response(['records' => collect($request['records'])->mapWithKeys(fn ($r) => [$r['uuid'] => null])->all(), 'logs' => collect($request['logs'] ?? [])->pluck('uuid')->all()]);
                })(),
                default => Http::response($this->emptySnapshot()),
            };
        });

        $status = app(AttendanceSync::class)->run();
        $this->assertTrue($status['online']);
        $this->assertSame('amina@example.test', $sent[0]['records'][0]['email']);
        $this->assertSame('operator@example.test', $sent[0]['records'][0]['signin_scanned_by']);
        $this->assertNotNull(DB::table('attendance_audit_logs')->value('synced_at'));
        $record->refresh();
        $this->assertSame([1, 2], [$record->synced_version, $record->sync_version]);
        $this->assertSame(1, $status['waiting']);

        app(AttendanceSync::class)->run();
        $this->assertSame(2, $record->fresh()->synced_version);
        $this->assertSame(0, app(AttendanceSync::class)->status()['waiting']);
    }

    public function test_venue_keeps_scans_waiting_while_the_live_site_is_unreachable(): void
    {
        $this->asVenue();
        DailyAttendance::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $this->staff->id, 'staff_name' => 'Amina Bello', 'attendance_date' => $this->day->date, 'status' => 'present', 'checked_in_at' => now()]);
        $offline = true;
        $uuid = DailyAttendance::first()->sync_uuid;
        Http::fake(function (ClientRequest $request) use (&$offline, $uuid) {
            if ($offline) {
                throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
            }
            return match (true) {
                str_ends_with($request->url(), '/ping') => Http::response(['time' => now()->toIso8601String()]),
                str_contains($request->url(), '/upload') => Http::response(['records' => [$uuid => 'This person (amina@example.test) is not on the live site.'], 'logs' => []]),
                default => Http::response($this->emptySnapshot()),
            };
        });
        $status = app(AttendanceSync::class)->run();
        $this->assertFalse($status['online']);
        $this->assertSame(1, $status['waiting']);
        $this->assertStringContainsString('could not be reached', $status['error']);
        $this->assertNull(DailyAttendance::first()->synced_version);

        $offline = false;
        $status = app(AttendanceSync::class)->run();
        $this->assertSame([1, 1], [$status['waiting'], $status['problems']]);
        $this->assertSame('This person (amina@example.test) is not on the live site.', DailyAttendance::first()->sync_error);
    }

    public function test_venue_downloads_people_badges_and_phone_sign_ins_from_the_live_site(): void
    {
        $this->asVenue();
        $live = [
            'name' => 'Ngozi Adeyemi', 'email' => 'ngozi@example.test', 'role' => 'staff', 'active' => true, 'permissions' => null,
            'staff_number' => 'S-77', 'department' => null, 'designation' => null, 'group_name' => null, 'slug' => 'ngozi-adeyemi-live',
            'qr_code' => '5555666677778888', 'print_code' => '9999000011112222', 'enrolled' => true,
        ];
        $phone = $this->venueRecord(['email' => 'ngozi@example.test', 'qr_code' => '5555666677778888', 'origin' => 'online', 'checked_in_at' => '2026-11-02T08:15:00+01:00', 'signin_method' => 'device', 'signin_device_verified' => true, 'signin_scanned_by' => null]);
        Http::fake([
            '*/ping' => Http::response(['time' => now()->toIso8601String()]),
            '*/snapshot*' => Http::response($this->emptySnapshot([
                'days' => [['date' => '2026-11-02', 'attendance_required' => true, 'cancelled' => false, 'checkin_opens' => '07:30', 'checkin_closes' => '10:00', 'late_after' => '08:45', 'checkout_opens' => '15:00', 'checkout_closes' => '18:00']],
                'people' => [$live, ['name' => 'Amina Bello', 'email' => 'amina@example.test', 'role' => 'staff', 'active' => true, 'permissions' => null, 'staff_number' => null, 'department' => null, 'designation' => null, 'group_name' => null, 'slug' => $this->staff->slug, 'qr_code' => $this->staff->qr_code, 'print_code' => $this->staff->print_code, 'enrolled' => false]],
                'badges' => [['slug' => 'badge-ngozi', 'email' => 'ngozi@example.test', 'category' => 'staff', 'snapshot' => ['name' => 'Ngozi Adeyemi'], 'version' => 1, 'generated_at' => now()->toIso8601String(), 'generated_by' => 'operator@example.test', 'collection_code' => 'SCX-ABCDEF123456', 'printed_at' => null, 'collected_at' => null, 'materials_collected_at' => null]],
                'attendance' => [$phone],
            ])),
        ]);

        $status = app(AttendanceSync::class)->run();
        $this->assertTrue($status['online']);
        $ngozi = User::where('email', 'ngozi@example.test')->firstOrFail();
        $this->assertSame(['5555666677778888', 'ngozi-adeyemi-live'], [$ngozi->qr_code, $ngozi->slug]);
        $this->assertTrue($ngozi->programmes()->exists());
        $this->assertFalse($this->staff->programmes()->exists());
        $this->assertStringStartsWith('08:45', $this->day->fresh()->late_after);
        $this->assertSame('u:'.$ngozi->id, Badge::where('slug', 'badge-ngozi')->firstOrFail()->subject_key);

        $record = DailyAttendance::where('user_id', $ngozi->id)->firstOrFail();
        $this->assertSame(['08:15:00', 'device', 'present'], [$record->checked_in_at->format('H:i:s'), $record->signin_method, $record->status]);
        // Downloaded from the live site, so it is not uploaded back.
        $this->assertSame($record->sync_version, $record->synced_version);
        $this->assertSame(0, $status['waiting']);
        $this->assertNotNull(Cache::get(AttendanceSync::CURSOR));
    }

    public function test_scanner_pages_show_the_sync_line_and_sync_now_runs_on_the_venue_only(): void
    {
        $this->actingAs($this->admin);
        $this->get('/attendance/scanner')->assertOk()->assertSee('Checking venue uploads')->assertDontSee('Sync now');
        $this->getJson('/attendance/sync')->assertOk()->assertJsonPath('mode', 'online');
        $this->postJson('/attendance/sync')->assertNotFound();

        $this->asVenue();
        Http::fake(['*/ping' => Http::response(['time' => now()->toIso8601String()]), '*' => Http::response($this->emptySnapshot())]);
        $this->get('/attendance/scanner')->assertOk()->assertSee('Sync now')->assertSee('attendance-sync.js', false);
        $this->get('/attendance/scanner/sign-in')->assertOk()->assertSee('data-attendance-sync', false);
        $this->postJson('/attendance/sync')->assertOk()->assertJsonPath('mode', 'venue')->assertJsonPath('online', true)->assertJsonPath('waiting', 0);
        $this->getJson('/api/attendance-sync/ping')->assertNotFound();
        $this->actingAs($this->staff)->getJson('/attendance/sync')->assertForbidden();
    }
}
