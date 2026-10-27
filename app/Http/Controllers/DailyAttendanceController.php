<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceRequest;
use App\Models\DailyAttendance;
use App\Models\ProgrammeDay;
use App\Services\Access;
use App\Services\AttendanceSecurity;
use App\Services\AttendanceService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyAttendanceController extends Controller
{
    public function index(Request $r, AttendanceService $service)
    {
        Access::enrolled();
        $manager = $r->user()->canManage('attendance');
        $staff = $manager ? Access::staff()->orderBy('name')->get() : collect([$r->user()]);
        $selected = $r->filled('staff') ? $staff->firstWhere('id', (int) $r->staff) : $staff->first();
        abort_if($r->filled('staff') && ! $selected, 403);
        $days = $service->days()->with('week')->orderBy('date')->get();
        $records = $selected ? DailyAttendance::where('programme_id', Access::programme()->id)->where('user_id', $selected->id)->get()->keyBy('programme_day_id') : collect();
        $summary = $selected ? $service->summary($selected) : null;
        $device = $selected ? AttendanceDevice::where('active_user_id', $selected->id)->first() : null;
        $replacements = $manager ? AttendanceDeviceRequest::with(['user', 'approver'])->where('programme_id', Access::programme()->id)->latest()->limit(50)->get() : AttendanceDeviceRequest::where('user_id', $r->user()->id)->latest()->limit(10)->get();
        $policy = Access::programme()->settings['attendance_security'] ?? [];

        return response()->view('daily.index', compact('manager', 'staff', 'selected', 'days', 'records', 'summary', 'device', 'replacements', 'policy'))->header('Cache-Control', 'private, no-store');
    }

    public function live(Request $r)
    {
        Access::manage('attendance');
        $r->validate(['date' => 'nullable|date', 'department' => 'nullable|string|max:255', 'name' => 'nullable|string|max:255', 'status' => 'nullable|in:present,late,absent,excused,pending,in,out']);
        $date = $r->input('date', now('Africa/Lagos')->toDateString());
        $day = app(AttendanceService::class)->days()->whereDate('date', $date)->first();
        $q = Access::staff()->where('active', true)->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id)->where(fn ($q) => $q->whereNull('enrolments.created_at')->orWhereDate('enrolments.created_at', '<=', $date)));
        if ($r->filled('department')) {
            $q->where('department', $r->department);
        }
        if ($r->filled('name')) {
            $q->where('name', 'like', '%'.$r->name.'%');
        }
        $staff = $q->orderBy('name')->get();
        $records = $day ? DailyAttendance::where('programme_id', Access::programme()->id)->where('programme_day_id', $day->id)->whereIn('user_id', $staff->pluck('id'))->get()->keyBy('user_id') : collect();
        $expected = $day?->attendance_required && ! $day->cancelled && $date >= Access::programme()->starts_on->toDateString() && $date <= Access::programme()->ends_on->toDateString() ? $staff->count() : 0;
        $present = $records->whereNotNull('checked_in_at')->count();
        $out = $records->whereNotNull('checked_out_at')->count();
        $counts = ['Expected' => $expected, 'Present' => $present, 'Not yet signed in' => max(0, $expected - $present), 'On time' => $records->where('status', 'present')->count(), 'Late' => $records->where('status', 'late')->count(), 'Currently signed in' => $present - $out, 'Signed out' => $out, 'Attendance %' => $expected ? round(100 * $present / $expected, 1).'%' : 'N/A'];

        return response()->view('daily.live', compact('day', 'staff', 'records', 'counts'))->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $r, ProgrammeDay $day, AttendanceService $service)
    {
        Access::manage('attendance');
        $service->assertDay($day);
        $v = $r->validate(['checkin_opens' => 'nullable|required_if:attendance_required,1|date_format:H:i', 'checkin_closes' => 'nullable|required_if:attendance_required,1|date_format:H:i|after:checkin_opens', 'late_after' => 'nullable|required_if:attendance_required,1|date_format:H:i|after_or_equal:checkin_opens|before_or_equal:checkin_closes', 'checkout_opens' => 'nullable|required_if:attendance_required,1|date_format:H:i|after_or_equal:checkin_opens', 'checkout_closes' => 'nullable|required_if:attendance_required,1|date_format:H:i|after:checkout_opens']);
        $day->update([...$v, 'attendance_required' => $r->boolean('attendance_required'), 'cancelled' => $r->boolean('cancelled')]);
        app(AttendanceSecurity::class)->log('day_policy.updated', true, 'Day '.$day->id.' attendance windows updated.');

        return back()->with('success', 'Daily attendance policy saved. All times are Africa/Lagos.');
    }

    public function qr(Request $r, ?string $mode = null)
    {
        Access::manage('attendance');
        $writer = new Writer(new ImageRenderer(new RendererStyle(340), new SvgImageBackEnd));
        $links = [];
        $codes = [];
        foreach (['in' => 'sign-in', 'out' => 'sign-out'] as $key => $path) {
            $links[$key] = rtrim(config('attendance.origin'), '/').'/attendance/'.$path;
            $codes[$key] = $writer->writeString($links[$key]);
        }
        if ($mode) {
            abort_unless(isset($codes[$mode]), 404);

            return response($codes[$mode], 200, ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'attachment; filename="icrc-attendance-'.$mode.'.svg"']);
        }

        return view('daily.qr', compact('links', 'codes'));
    }

    public function legacy()
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        return view('daily.legacy', ['records' => DB::table('legacy_session_attendance')->join('users', 'users.id', '=', 'legacy_session_attendance.user_id')->join('programme_sessions', 'programme_sessions.id', '=', 'legacy_session_attendance.session_id')->select('legacy_session_attendance.*', 'users.name', 'programme_sessions.title')->get()]);
    }
}
