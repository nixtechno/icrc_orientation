<?php

namespace App\Http\Controllers;

use App\Models\AttendanceChallenge;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceRequest;
use App\Models\Programme;
use App\Models\User;
use App\Services\Access;
use App\Services\AttendanceSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AttendanceSecurityController extends Controller
{
    public function page(Request $r, string $mode)
    {
        abort_unless(in_array($mode, ['sign-in', 'sign-out', 'device']), 404);
        app(AttendanceSecurity::class)->staff($r->user());
        $device = AttendanceDevice::where('active_user_id', $r->user()->id)->first();

        return response()->view('daily.verify', compact('mode', 'device'))->header('Cache-Control', 'private, no-store');
    }

    public function ceremony(Request $r, string $purpose, string $step, AttendanceSecurity $security)
    {
        abort_unless(in_array($purpose, ['register', 'in', 'out']) && in_array($step, ['options', 'verify']), 404);
        try {
            if ($step === 'options') {
                return response()->json($security->options($r, $purpose))->header('Cache-Control', 'no-store');
            }
            $security->finish($r, $purpose);

            return response()->json(['message' => $purpose === 'register' ? 'Authorized attendance device registered.' : 'Attendance sign-'.$purpose.' recorded successfully.'])->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $e) {
            $message = $e->getMessage();
            $security->log($purpose.'.failed', false, $message ?: 'Verification rejected.');
            if ($e->getStatusCode() === 409) {
                $security->log('attendance.duplicate', false, $message);
            }
            if (preg_match('/location|GPS|venue/i', $message)) {
                $security->log('location.failed', false, $message);
            }
            if (preg_match('/credential|authentication|WebAuthn|device/i', $message)) {
                $security->log('device.invalid', false, $message);
            }
            throw $e;
        } catch (ValidationException $e) {
            $security->log($purpose.'.failed', false, 'Required request fields or current password were invalid.');
            throw $e;
        }
    }

    public function clientFailure(Request $r, AttendanceSecurity $security)
    {
        $security->staff($r->user());
        $v = $r->validate(['reason' => 'required|in:location_denied,location_unavailable,location_timeout,device_cancelled,device_unsupported', 'purpose' => 'required|in:register,in,out']);
        $security->log($v['purpose'].'.failed', false, $v['reason']);
        if (str_starts_with($v['reason'], 'location_')) {
            $security->log('location.failed', false, $v['reason']);
        }

        return response()->noContent();
    }

    public function replacement(Request $r, AttendanceSecurity $security)
    {
        $security->staff($r->user());
        $v = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($r, $v, $security) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(AttendanceDevice::where('user_id', $r->user()->id)->exists(), 422, 'Register your first device before requesting replacement.');
            abort_if(AttendanceDeviceRequest::where('user_id', $r->user()->id)->where(function ($q) {
                $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'approved')->whereNull('enrolled_at'));
            })->exists(), 409, 'A device replacement request is already awaiting completion.');
            AttendanceDeviceRequest::create(['programme_id' => Access::programme()->id, 'user_id' => $r->user()->id, 'reason' => $v['reason']]);
            $security->log('device.replacement_requested', true, 'Staff requested device replacement.');
        });

        return back()->with('success', 'Device replacement request submitted for approval.');
    }

    public function decision(Request $r, AttendanceDeviceRequest $replacement, AttendanceSecurity $security)
    {
        Access::manage('attendance');
        abort_unless($replacement->programme_id === Access::programme()->id, 404);
        abort_if($replacement->user_id === $r->user()->id, 403, 'You cannot approve your own device replacement.');
        $v = $r->validate(['decision' => 'required|in:approved,rejected', 'decision_reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($r, $replacement, $v, $security) {
            User::whereKey($replacement->user_id)->lockForUpdate()->firstOrFail();
            $row = AttendanceDeviceRequest::whereKey($replacement->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'pending', 409, 'This request has already been decided.');
            if ($v['decision'] === 'approved') {
                AttendanceDevice::where('active_user_id', $row->user_id)->update(['active_user_id' => null, 'revoked_at' => now()]);
                AttendanceChallenge::where('user_id', $row->user_id)->whereNull('used_at')->update(['used_at' => now()]);
                $security->log('device.revoked', true, 'Previous credential revoked following approved replacement.', $row->user_id);
            }
            $row->update(['status' => $v['decision'], 'approved_by' => $r->user()->id, 'decision_reason' => $v['decision_reason'], 'decided_at' => now()]);
            $security->log('device.replacement_'.$v['decision'], true, 'Request '.$row->id.': '.$v['decision_reason'], $row->user_id);
        });

        return back()->with('success', 'Replacement decision recorded. Approval does not mark attendance.');
    }

    public function settings(Request $r, AttendanceSecurity $security)
    {
        Access::manage('attendance');
        $v = $r->validate(['venue_name' => 'required|string|max:200', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'radius' => 'required|numeric|min:1|max:10000', 'max_accuracy' => 'required|numeric|min:1|lte:radius', 'max_age_seconds' => 'required|integer|between:10,120', 'location_retention_days' => 'required|integer|between:1,365', 'audit_retention_days' => 'required|integer|between:30,3650', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on']);
        DB::transaction(function () use ($v, $security) {
            $p = Programme::whereKey(Access::programme()->id)->lockForUpdate()->firstOrFail();
            $s = $p->settings ?? [];
            $s['attendance_security'] = array_diff_key($v, array_flip(['starts_on', 'ends_on']));
            $p->update(['settings' => $s, 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'], 'timezone' => 'Africa/Lagos']);
            $security->log('policy.updated', true, 'Venue, attendance dates and retention policy updated.');
        });

        return back()->with('success', 'Attendance security policy saved.');
    }

    public function audit()
    {
        Access::manage('attendance');
        $logs = DB::table('attendance_audit_logs')->leftJoin('users', 'users.id', '=', 'attendance_audit_logs.user_id')->leftJoin('users as actors', 'actors.id', '=', 'attendance_audit_logs.actor_id')->where('attendance_audit_logs.programme_id', Access::programme()->id)->select('attendance_audit_logs.*', 'users.name', 'users.staff_number', 'actors.name as actor_name')->orderByDesc('attendance_audit_logs.id')->paginate(50);

        return response()->view('daily.audit', compact('logs'))->header('Cache-Control', 'private, no-store');
    }
}
