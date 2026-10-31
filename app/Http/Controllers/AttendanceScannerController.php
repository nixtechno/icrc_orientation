<?php

namespace App\Http\Controllers;

use App\Models\{Badge, DailyAttendance, Programme, ProgrammeDay, User};
use App\Services\{Access, AttendanceSecurity, AttendanceService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceScannerController extends Controller
{
    public function index()
    {
        Access::manage('attendance');
        return response()->view('daily.scanner')->header('Cache-Control', 'private, no-store');
    }

    public function page(string $mode)
    {
        Access::manage('attendance');
        return response()->view('daily.scanner-scan', compact('mode'))->header('Cache-Control', 'private, no-store');
    }

    public function record(Request $request, string $mode, AttendanceSecurity $security, AttendanceService $service)
    {
        Access::manage('attendance');
        $value = trim($request->validate(['code'=>'required|string|max:2048'])['code']);
        // Read the QR payload locally. Never follow or fetch a scanned URL.
        $path = parse_url($value, PHP_URL_PATH);
        $slug = is_string($path) && preg_match('~(?:^|/)badges/([a-zA-Z0-9-]+)/verify/?$~D', $path, $matches)
            ? $matches[1] : $value;
        abort_unless(preg_match('/^[a-zA-Z0-9-]+$/D', $slug), 422, 'Scan a valid ICRC staff badge QR code.');
        $badge = Badge::where('programme_id', Access::programme()->id)->where('slug', $slug)->first();
        abort_unless($badge && $badge->category === 'staff' && $badge->user_id, 422, 'This QR code is not an enrolled staff badge for this programme.');
        $direction = $mode === 'sign-in' ? 'in' : 'out';
        $record = DB::transaction(function () use ($badge, $direction, $security, $service) {
            $user = User::whereKey($badge->user_id)->lockForUpdate()->firstOrFail();
            $programme = Programme::whereKey(Access::programme()->id)->lockForUpdate()->firstOrFail();
            $day = $service->days()->whereDate('date', now('Africa/Lagos')->toDateString())->first();
            if ($day) ProgrammeDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            $day = $security->eligible($user, $direction);
            $stamp = now();
            $prefix = $direction === 'in' ? 'signin' : 'signout';
            $fields = [$prefix.'_method'=>'scanner', $prefix.'_scanned_by'=>auth()->id()];
            if ($direction === 'in') {
                $late = $service->time($day, 'late_after');
                abort_unless($late, 422, 'The late arrival threshold has not been configured.');
                $record = DailyAttendance::create([
                    ...$fields, 'programme_id'=>$programme->id, 'programme_day_id'=>$day->id,
                    'user_id'=>$user->id, 'staff_name'=>$user->name, 'staff_number_snapshot'=>$user->staff_number,
                    'attendance_date'=>$day->date, 'checked_in_at'=>$stamp,
                    'status'=>$stamp->gt($late) ? 'late' : 'present',
                    'punctuality_status'=>$stamp->gt($late) ? 'Late' : 'On Time', 'recorded_by'=>auth()->id(),
                ]);
            } else {
                $record = DailyAttendance::where('programme_id', $programme->id)->where('programme_day_id', $day->id)
                    ->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $record->update([...$fields, 'checked_out_at'=>$stamp,
                    'duration_seconds'=>max(0, (int) $record->checked_in_at->diffInSeconds($stamp))]);
            }
            $security->log('attendance.scanner.'.$direction, true, 'Staff badge scanned by attendance manager. Badge '.$badge->getRouteKey().'.', $user->id);
            return $record;
        });
        $label = $direction === 'in' ? 'Sign-in' : 'Sign-out';
        $time = ($direction === 'in' ? $record->checked_in_at : $record->checked_out_at)->timezone('Africa/Lagos')->format('H:i:s');
        $message = $record->staff_name.' — '.$label.' recorded at '.$time.'.';
        if ($request->expectsJson()) return response()->json(['message'=>$message, 'name'=>$record->staff_name, 'time'=>$time, 'status'=>$record->status])->header('Cache-Control','private, no-store');
        return back()->with('success', $message);
    }
}
