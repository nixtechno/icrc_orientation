<?php

namespace Tests\Feature;

use App\Models\AttendanceChallenge;
use App\Models\AttendanceDevice;
use App\Models\Programme;
use App\Models\ProgrammeDay;
use App\Models\User;
use App\Services\AttendanceSecurity as S;
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\AttendanceAuthenticator;
use Tests\TestCase;

class AttendanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_submitting_the_same_valid_assertion_create_exactly_one_record(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('icrc_orientation_testing', config('database.connections.mysql.database'));
        app(TimetableImporter::class)->run();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'Africa/Lagos'));
        $u = User::factory()->create();
        $u->forceFill(['role' => 'staff', 'active' => true])->save();
        $u->programmes()->attach(Programme::first()->id);
        $day = ProgrammeDay::where('number', 1)->firstOrFail();
        $day->update(['attendance_required' => true, 'checkin_opens' => '08:00', 'checkin_closes' => '10:00', 'late_after' => '08:30', 'checkout_opens' => '09:00', 'checkout_closes' => '18:00']);
        $p = Programme::first();
        $settings = $p->settings;
        $settings['attendance_security'] = ['latitude' => 9, 'longitude' => 7, 'radius' => 200, 'max_accuracy' => 30, 'max_age_seconds' => 45];
        $p->update(['settings' => $settings]);
        $a = new AttendanceAuthenticator;
        $id = S::encode($a->id);
        $device = AttendanceDevice::create(['user_id' => $u->id, 'active_user_id' => $u->id, 'credential_id' => $id, 'credential_hash' => hash('sha256', $id), 'public_key' => openssl_pkey_get_details($a->key)['key'], 'sign_count' => 0, 'label' => 'Race test', 'registered_at' => now()]);
        $session = str_repeat('r', 40);
        $challenge = S::encode(random_bytes(32));
        $c = AttendanceChallenge::create(['id' => (string) Str::uuid(), 'programme_id' => $p->id, 'user_id' => $u->id, 'device_id' => $device->id, 'programme_day_id' => $day->id, 'purpose' => 'in', 'challenge' => $challenge, 'session_hash' => hash('sha256', $session), 'expires_at' => now()->addMinutes(2)]);
        $payload = $a->assertion(['challenge_id' => $c->id, 'options' => ['publicKey' => ['challenge' => $challenge]]]);
        $payload['location'] = ['latitude' => 9, 'longitude' => 7, 'accuracy' => 5, 'timestamp' => now()->getTimestampMs()];
        $file = tempnam(sys_get_temp_dir(), 'icrc-race-');
        file_put_contents($file, json_encode(['now' => now()->toDateTimeString(), 'user' => $u->id, 'session' => $session, 'payload' => $payload, 'start' => microtime(true) + 1]));
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/attendance-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $workers[] = [$process, $pipes];
            }
            $results = [];
            foreach ($workers as [$process,$pipes]) {
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $this->assertSame(0, $exit, $err);
                $results[] = trim($out);
            }
            sort($results);
            $this->assertSame(['200', '422'], $results);
            $this->assertDatabaseCount('daily_attendance', 1);
            $this->assertSame(1, AttendanceDevice::first()->sign_count);
        } finally {
            @unlink($file);
        }
    }
}
