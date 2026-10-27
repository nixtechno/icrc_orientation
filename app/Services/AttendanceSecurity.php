<?php

namespace App\Services;

use App\Models\AttendanceChallenge;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceRequest;
use App\Models\DailyAttendance;
use App\Models\Programme;
use App\Models\ProgrammeDay;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use lbuchs\WebAuthn\Attestation\AuthenticatorData;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

class AttendanceSecurity
{
    public static function encode(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }

    public static function decode(string $v): string
    {
        abort_unless(strlen($v) <= 100000 && preg_match('/^[A-Za-z0-9_-]+$/D', $v), 422, 'Malformed authentication data.');
        $out = base64_decode(strtr($v, '-_', '+/'), true);
        abort_if($out === false, 422, 'Malformed authentication data.');

        return $out;
    }

    public function log(string $event, bool $verified, string $reason, ?int $userId = null): void
    {
        DB::table('attendance_audit_logs')->insert(['programme_id' => Access::programme()->id, 'user_id' => $userId ?? auth()->id(), 'actor_id' => auth()->id(), 'event' => $event, 'verified' => $verified, 'reason' => $reason, 'ip_address' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 1000), 'created_at' => now()]);
    }

    public function webauthn(): WebAuthn
    {
        $origin = config('attendance.origin');
        $url = parse_url($origin);
        abort_unless($url && ($url['host'] ?? '') === config('attendance.rp_id') && ! isset($url['path']) && ! isset($url['query']) && ! isset($url['user']) && ! isset($url['fragment']) && (($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http' && ($url['host'] ?? '') === 'localhost')), 422, 'Attendance requires a configured HTTPS origin and matching RP ID (localhost is permitted for development).');

        return new WebAuthn('ICRC Orientation attendance', config('attendance.rp_id'), null, true);
    }

    public function staff(User $user): void
    {
        Access::enrolled($user);
        abort_unless($user->role === 'staff', 403, 'Attendance verification is available to enrolled staff only.');
    }

    public function location(array $v): array
    {
        $s = Access::programme()->settings['attendance_security'] ?? [];
        abort_unless(isset($s['latitude'],$s['longitude'],$s['radius'],$s['max_accuracy']), 422, 'The attendance venue has not been configured.');
        foreach (['latitude', 'longitude', 'accuracy', 'timestamp'] as $key) {
            abort_unless(isset($v[$key]) && is_numeric($v[$key]) && is_finite((float) $v[$key]), 422, 'Current location is required. Enable location permission and retry.');
        }
        $lat = (float) $v['latitude'];
        $lon = (float) $v['longitude'];
        $accuracy = (float) $v['accuracy'];
        abort_unless(abs($lat) <= 90 && abs($lon) <= 180, 422, 'Invalid location coordinates.');
        abort_unless($accuracy > 0 && $accuracy <= (float) $s['max_accuracy'], 422, 'GPS accuracy is insufficient. Move outdoors or enable precise location.');
        $age = now()->getTimestampMs() - (float) $v['timestamp'];
        abort_unless($age >= -5000 && $age <= 1000 * ($s['max_age_seconds'] ?? 45), 422, 'Location is stale. Obtain a fresh location and retry.');
        $a = sin(deg2rad($lat - $s['latitude']) / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($s['latitude'])) * sin(deg2rad($lon - $s['longitude']) / 2) ** 2;
        $distance = 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
        abort_unless($distance + $accuracy <= (float) $s['radius'], 422, 'Your location (including its accuracy margin) is outside the approved venue radius.');

        return ['latitude' => $lat, 'longitude' => $lon, 'accuracy' => $accuracy, 'reported_at' => (float) $v['timestamp'], 'distance_meters' => round($distance, 2), 'venue' => $s['venue_name'] ?? 'Attendance venue'];
    }

    public function eligible(User $user, string $mode)
    {
        $this->staff($user);
        $p = Access::programme();
        $today = now('Africa/Lagos')->toDateString();
        abort_unless($today >= $p->starts_on->toDateString() && $today <= $p->ends_on->toDateString(), 422, 'The programme is outside its attendance dates.');
        $svc = app(AttendanceService::class);
        $day = $svc->days()->whereDate('date', $today)->first();
        abort_unless($day && $day->attendance_required && ! $day->cancelled, 422, 'Attendance is not required today.');
        $prefix = $mode === 'in' ? 'checkin' : 'checkout';
        $open = $svc->time($day, $prefix.'_opens');
        $close = $svc->time($day, $prefix.'_closes');
        abort_unless($open && $close && now()->betweenIncluded($open, $close), 422, 'The attendance '.$prefix.' window is closed or not configured.');
        $record = DailyAttendance::where('programme_id', $p->id)->where('programme_day_id', $day->id)->where('user_id', $user->id)->first();
        if ($mode === 'in') {
            abort_if($record, 409, 'Attendance is already recorded for today.');
        } else {
            abort_unless($record?->checked_in_at, 422, 'Sign in before signing out.');
            abort_if($record->checked_out_at, 409, 'You have already signed out today.');
        }

        return $day;
    }

    private function registrationAllowed(User $u): ?AttendanceDeviceRequest
    {
        abort_if(AttendanceDevice::where('active_user_id', $u->id)->exists(), 409, 'You already have an authorized attendance device.');
        $approval = AttendanceDeviceRequest::where('user_id', $u->id)->where('status', 'approved')->whereNull('enrolled_at')->latest('id')->first();
        abort_if(AttendanceDevice::where('user_id', $u->id)->exists() && ! $approval, 403, 'A supervisor must approve device replacement before registration.');

        return $approval;
    }

    public function options(Request $r, string $purpose): array
    {
        $u = $r->user();
        $this->staff($u);
        $web = $this->webauthn();
        $day = null;
        $device = null;
        if ($purpose === 'register') {
            $r->validate(['password' => 'required|current_password']);
            $this->registrationAllowed($u);
            $args = $web->getCreateArgs(hash('sha256', 'icrc-attendance:'.$u->id, true), $u->email, $u->name, 120, false, true, false);
            $args->publicKey->attestation = 'none';
        } else {
            $r->validate(['location' => 'required|array']);
            $day = $this->eligible($u, $purpose);
            $this->location($r->input('location', []));
            $device = AttendanceDevice::where('active_user_id', $u->id)->whereNull('revoked_at')->first();
            abort_unless($device, 422, 'Register your authorized attendance device first.');
            $args = $web->getGetArgs([self::decode($device->credential_id)], 120, false, false, false, false, true, true);
        }
        $challenge = DB::transaction(function () use ($r, $u, $purpose, $web, $day, $device) {
            User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            AttendanceChallenge::where('user_id', $u->id)->where('purpose', $purpose)->whereNull('used_at')->update(['used_at' => now()]);

            return AttendanceChallenge::create(['id' => (string) Str::uuid(), 'programme_id' => Access::programme()->id, 'user_id' => $u->id, 'device_id' => $device?->id, 'programme_day_id' => $day?->id, 'purpose' => $purpose, 'challenge' => self::encode($web->getChallenge()->getBinaryString()), 'session_hash' => hash('sha256', $r->session()->getId()), 'expires_at' => now()->addSeconds(config('attendance.challenge_seconds', 120))]);
        });

        return ['challenge_id' => $challenge->id, 'options' => $args];
    }

    public function finish(Request $r, string $purpose): void
    {
        $u = $r->user();
        $this->staff($u);
        $web = $this->webauthn();
        $v = $r->validate(['challenge_id' => 'required|uuid', 'id' => 'required|string|max:4096', 'clientDataJSON' => 'required|string|max:20000', 'attestationObject' => 'nullable|string|max:100000', 'authenticatorData' => 'nullable|string|max:20000', 'signature' => 'nullable|string|max:20000', 'userHandle' => 'nullable|string|max:1024', 'label' => 'nullable|string|max:100']);
        if ($purpose !== 'register') {
            $r->validate(['location' => 'required|array']);
        }
        // Consumed in its own committed transaction, including on failed verification.
        $c = DB::transaction(function () use ($r, $u, $purpose, $v) {
            $c = AttendanceChallenge::whereKey($v['challenge_id'])->lockForUpdate()->first();
            abort_unless($c && $c->user_id === $u->id && $c->programme_id === Access::programme()->id && $c->purpose === $purpose && ! $c->used_at && $c->expires_at->isFuture() && hash_equals($c->session_hash, hash('sha256', $r->session()->getId())), 422, 'Authentication challenge is invalid, expired or already used. Start again.');
            $c->update(['used_at' => now()]);

            return $c;
        });
        $client = self::decode($v['clientDataJSON']);
        $data = json_decode($client, true);
        abort_unless(is_array($data) && ($data['origin'] ?? '') === config('attendance.origin') && ! ($data['crossOrigin'] ?? false) && ! isset($data['topOrigin']) && ($data['type'] ?? '') === ($purpose === 'register' ? 'webauthn.create' : 'webauthn.get'), 422, 'Invalid WebAuthn origin or ceremony.');
        try {
            if ($purpose === 'register') {
                abort_unless(! empty($v['attestationObject']), 422, 'Registration response is missing.');
                $result = $web->processCreate($client, self::decode($v['attestationObject']), self::decode($c->challenge), true, true, false);
                abort_if($result->isBackupEligible || $result->isBackedUp, 422, 'This device offered a synchronized passkey. A non-synchronized, device-bound credential is mandatory; use a compatible device.');
                abort_unless(hash_equals($result->credentialId, self::decode($v['id'])), 422, 'Credential ID mismatch.');
                DB::transaction(function () use ($u, $result, $v, $c) {
                    $fresh = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
                    $this->staff($fresh);
                    abort_unless($c->expires_at->isFuture(), 422, 'Registration expired.');
                    $approval = $this->registrationAllowed($fresh);
                    $id = self::encode($result->credentialId);
                    abort_if(AttendanceDevice::where('credential_hash', hash('sha256', $id))->exists(), 409, 'This credential is already registered.');
                    AttendanceDevice::create(['user_id' => $u->id, 'active_user_id' => $u->id, 'credential_id' => $id, 'credential_hash' => hash('sha256', $id), 'public_key' => $result->credentialPublicKey, 'sign_count' => $result->signatureCounter ?? 0, 'aaguid' => bin2hex($result->AAGUID), 'label' => $v['label'] ?? 'My attendance device', 'registered_at' => now()]);
                    $approval?->update(['enrolled_at' => now()]);
                    $this->log('device.registered', true, 'Non-backup credential registered with user verification.');
                });

                return;
            }
            DB::transaction(function () use ($u, $r, $v, $client, $web, $c, $purpose) {
                $fresh = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
                Programme::whereKey($c->programme_id)->lockForUpdate()->firstOrFail();
                ProgrammeDay::whereKey($c->programme_day_id)->lockForUpdate()->firstOrFail();
                $day = $this->eligible($fresh, $purpose);
                abort_unless($c->expires_at->isFuture() && $day->id === $c->programme_day_id, 422, 'Attendance challenge expired or day changed.');
                $device = AttendanceDevice::whereKey($c->device_id)->lockForUpdate()->first();
                abort_unless($device && ! $device->revoked_at && $device->active_user_id === $u->id && hash_equals($device->credential_id, $v['id']), 403, 'Use the authorized attendance credential registered to your own account.');
                if (! empty($v['userHandle'])) {
                    abort_unless(hash_equals(hash('sha256', 'icrc-attendance:'.$u->id, true), self::decode($v['userHandle'])), 403, 'Credential belongs to another account.');
                }
                abort_unless(! empty($v['authenticatorData']) && ! empty($v['signature']), 422, 'Authentication response is missing.');
                $binary = self::decode($v['authenticatorData']);
                $web->processGet($client, $binary, self::decode($v['signature']), $device->public_key, self::decode($c->challenge), $device->sign_count, true, true);
                $auth = new AuthenticatorData($binary);
                abort_if($auth->getIsBackupEligible() || $auth->getIsBackup(), 422, 'Synchronized credentials cannot authorize attendance.');
                $location = $this->location($r->input('location', []));
                // Recheck after cryptographic work, including a window closing during verification.
                $this->eligible($fresh, $purpose);
                abort_unless($c->expires_at->isFuture(), 422, 'Attendance challenge expired. Start again.');
                $stamp = now();
                $prefix = $purpose === 'in' ? 'signin' : 'signout';
                $fields = [$prefix.'_device_id' => $device->id, $prefix.'_device_verified' => true, $prefix.'_location_verified' => true, $prefix.'_location' => $location];
                if ($purpose === 'in') {
                    $late = app(AttendanceService::class)->time($day, 'late_after');
                    abort_unless($late, 422, 'The late arrival threshold has not been configured.');
                    DailyAttendance::create([...$fields, 'programme_id' => Access::programme()->id, 'programme_day_id' => $day->id, 'user_id' => $u->id, 'staff_name' => $fresh->name, 'staff_number_snapshot' => $fresh->staff_number, 'attendance_date' => $day->date, 'checked_in_at' => $stamp, 'status' => $stamp->gt($late) ? 'late' : 'present', 'punctuality_status' => $stamp->gt($late) ? 'Late' : 'On Time', 'recorded_by' => $u->id]);
                } else {
                    $record = DailyAttendance::where('programme_day_id', $day->id)->where('programme_id', Access::programme()->id)->where('user_id', $u->id)->lockForUpdate()->firstOrFail();
                    $record->update([...$fields, 'checked_out_at' => $stamp, 'duration_seconds' => max(0, (int) $record->checked_in_at->diffInSeconds($stamp))]);
                }
                $device->update(['sign_count' => $auth->getSignCount(), 'last_verified_at' => $stamp]);
                $this->log('attendance.'.$purpose, true, 'Device and location verified.');
            });
        } catch (WebAuthnException $e) {
            abort(422, 'Device verification failed. Use your registered device and start a fresh verification.');
        }
    }
}
