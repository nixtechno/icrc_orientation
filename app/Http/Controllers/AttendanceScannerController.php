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
        $logoUrl = Access::programme()->logoUrl();
        return response()->view('daily.scanner-scan', compact('mode', 'logoUrl'))->header('Cache-Control', 'private, no-store');
    }

    public function record(Request $request, string $mode, AttendanceSecurity $security, AttendanceService $service)
    {
        Access::manage('attendance');
        $value = trim($request->validate(['code'=>'required|string|max:2048'])['code']);
        $badges = Badge::where('programme_id', Access::programme()->id);
        if (ctype_digit($value)) {
            // Current badges carry the person's numeric QR ID.
            $owner = User::where('qr_code', $value)->value('id');
            $badge = $owner ? $badges->where('user_id', $owner)->orderByRaw("category = 'staff' desc")->latest('id')->first() : null;
        } else {
            // Badges printed earlier carry the verification link. Read it locally; never follow or fetch a scanned URL.
            $path = parse_url($value, PHP_URL_PATH);
            $slug = is_string($path) && preg_match('~(?:^|/)badges/([a-zA-Z0-9-]+)/verify/?$~D', $path, $matches)
                ? $matches[1] : $value;
            abort_unless(preg_match('/^[a-zA-Z0-9-]+$/D', $slug), 422, 'Scan a valid ICRC staff badge QR code.');
            $badge = $badges->where('slug', $slug)->first();
        }
        abort_unless($badge && $badge->category === 'staff' && $badge->user_id, 422, 'This QR code is not an enrolled staff badge for this programme.');
        // Explain account problems by name; the shared enrolment check rejects without a reason.
        $person = User::find($badge->user_id);
        abort_unless($person?->active, 403, ($person?->name ?? 'This person').' has not activated their account yet. Attendance can be recorded once they accept their invitation.');
        abort_unless($person->programmes()->where('programmes.id', Access::programme()->id)->exists(), 403, $person->name.' is not enrolled in this programme.');
        $direction = $mode === 'sign-in' ? 'in' : 'out';
        $repeat = false;
        $counts = true;
        $stamp = now();
        // Every valid staff badge scan is kept, whatever the time or day. Only required, non-cancelled days count towards
        // attendance, and late/on time still comes from the first sign-in, so the calculation is unchanged.
        $record = DB::transaction(function () use ($badge, $direction, $security, $service, $stamp, &$repeat, &$counts) {
            $user = User::whereKey($badge->user_id)->lockForUpdate()->firstOrFail();
            $programme = Programme::whereKey(Access::programme()->id)->lockForUpdate()->firstOrFail();
            $security->staff($user);
            $day = $service->days()->whereDate('date', $stamp->copy()->timezone('Africa/Lagos')->toDateString())->first();
            $badgeNote = 'Staff badge scanned by attendance manager. Badge '.$badge->getRouteKey().'.';
            if (! $day) {
                // No programme day to attach a record to: the scan is kept in the scan log only.
                $counts = false;
                $security->log('attendance.scanner.'.$direction, true, $badgeNote.' Not a programme day; scan logged only.', $user->id);
                return null;
            }
            ProgrammeDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            $counts = $day->attendance_required && ! $day->cancelled;
            $prefix = $direction === 'in' ? 'signin' : 'signout';
            $fields = [$prefix.'_method'=>'scanner', $prefix.'_scanned_by'=>auth()->id()];
            $record = DailyAttendance::where('programme_id', $programme->id)->where('programme_day_id', $day->id)
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if ($direction === 'in') {
                // The first sign-in is kept; later sign-in scans are logged but change nothing.
                $late = $service->time($day, 'late_after');
                $signIn = [...$fields, 'checked_in_at'=>$stamp, 'status'=>$late && $stamp->gt($late) ? 'late' : 'present',
                    'punctuality_status'=>$late ? ($stamp->gt($late) ? 'Late' : 'On Time') : null];
                if ($record?->checked_in_at) {
                    $repeat = true;
                } elseif ($record) {
                    // A sign-out was scanned before this sign-in.
                    $record->update([...$signIn, 'duration_seconds'=>$record->checked_out_at ? max(0, (int) $stamp->diffInSeconds($record->checked_out_at)) : null]);
                } else {
                    $record = DailyAttendance::create([
                        ...$signIn, 'programme_id'=>$programme->id, 'programme_day_id'=>$day->id,
                        'user_id'=>$user->id, 'staff_name'=>$user->name, 'staff_number_snapshot'=>$user->staff_number,
                        'attendance_date'=>$day->date, 'recorded_by'=>auth()->id(),
                    ]);
                }
            } else {
                // Each sign-out scan moves the sign-out to the latest time. Without a sign-in the record stays pending until one arrives.
                $repeat = (bool) $record?->checked_out_at;
                if ($record) {
                    $record->update([...$fields, 'checked_out_at'=>$stamp,
                        'duration_seconds'=>$record->checked_in_at ? max(0, (int) $record->checked_in_at->diffInSeconds($stamp)) : null]);
                } else {
                    $record = DailyAttendance::create([
                        ...$fields, 'programme_id'=>$programme->id, 'programme_day_id'=>$day->id,
                        'user_id'=>$user->id, 'staff_name'=>$user->name, 'staff_number_snapshot'=>$user->staff_number,
                        'attendance_date'=>$day->date, 'checked_out_at'=>$stamp, 'status'=>'pending', 'recorded_by'=>auth()->id(),
                    ]);
                }
            }
            $note = match (true) {
                $repeat && $direction === 'in' => ' Repeat sign-in scan; the first sign-in is kept.',
                $repeat => ' Repeat sign-out scan; sign-out moved to this scan.',
                default => '',
            };
            $security->log('attendance.scanner.'.$direction, true, $badgeNote.$note.($counts ? '' : ' Day does not count towards attendance.'), $user->id);
            return $record;
        });
        $name = $record?->staff_name ?? $person->name;
        $time = ($record ? ($direction === 'in' ? $record->checked_in_at : $record->checked_out_at) : $stamp)->timezone('Africa/Lagos')->format('H:i:s');
        $message = match (true) {
            ! $record => $name.' — scan logged at '.$time.'. Today is not a programme day, so it does not count towards attendance.',
            $repeat && $direction === 'in' => $name.' — already signed in at '.$time.'. The first sign-in is kept.',
            $repeat => $name.' — sign-out updated to '.$time.'.',
            default => $name.' — '.($direction === 'in' ? 'Sign-in' : 'Sign-out').' recorded at '.$time.'.',
        };
        if ($record && ! $counts) $message .= ' Today does not count towards attendance.';
        if ($request->expectsJson()) return response()->json(['message'=>$message, 'name'=>$name, 'time'=>$time, 'status'=>$record?->status])->header('Cache-Control','private, no-store');
        return back()->with('success', $message);
    }
}
