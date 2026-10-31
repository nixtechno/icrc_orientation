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
        $selected = $r->filled('staff') ? $staff->first(fn ($user) => (string) $user->id === (string) $r->staff || $user->getRouteKey() === $r->staff) : $staff->first();
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
        $r->validate(['date' => 'nullable|date', 'department' => 'nullable|string|max:255', 'name' => 'nullable|string|max:255', 'status' => 'nullable|in:present,late,absent,excused,pending,in,out', 'per_page' => 'sometimes|integer|in:10,25,50,100', 'attendance_page' => 'sometimes|integer|min:1', 'sort' => 'sometimes|in:name,department,status,signin', 'direction' => 'sometimes|in:asc,desc']);
        $date = $r->input('date') ?: now('Africa/Lagos')->toDateString();
        $day = app(AttendanceService::class)->days()->whereDate('date', $date)->first();
        $q = Access::staff()->where('active', true)->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id)->where(fn ($q) => $q->whereNull('enrolments.created_at')->orWhereDate('enrolments.created_at', '<=', $date)));
        if ($r->filled('department')) {
            $q->where('department', $r->department);
        }
        if ($r->filled('name')) {
            $q->where(fn ($query) => $query->where('users.name', 'like', '%'.$r->name.'%')->orWhere('users.staff_number', 'like', '%'.$r->name.'%')->orWhere('users.department', 'like', '%'.$r->name.'%'));
        }
        $q->leftJoin('daily_attendance as live_record', function ($join) use ($day) {
            $join->on('live_record.user_id', '=', 'users.id')->where('live_record.programme_id', Access::programme()->id)->where('live_record.programme_day_id', $day?->id ?? 0);
        });
        $totals = (clone $q)->toBase()->selectRaw("COUNT(users.id) as staff_count, SUM(CASE WHEN live_record.checked_in_at IS NOT NULL THEN 1 ELSE 0 END) as signed_in, SUM(CASE WHEN live_record.checked_out_at IS NOT NULL THEN 1 ELSE 0 END) as signed_out, SUM(CASE WHEN live_record.status = 'present' THEN 1 ELSE 0 END) as on_time, SUM(CASE WHEN live_record.status = 'late' THEN 1 ELSE 0 END) as late")->first();
        $expected = $day?->attendance_required && ! $day->cancelled && $date >= Access::programme()->starts_on->toDateString() && $date <= Access::programme()->ends_on->toDateString() ? (int) $totals->staff_count : 0;
        $present = (int) $totals->signed_in;
        $out = (int) $totals->signed_out;
        $counts = ['Expected' => $expected, 'Present' => $present, 'Not yet signed in' => max(0, $expected - $present), 'On time' => (int) $totals->on_time, 'Late' => (int) $totals->late, 'Currently signed in' => $present - $out, 'Signed out' => $out, 'Attendance %' => $expected ? round(100 * $present / $expected, 1).'%' : 'N/A'];
        if ($r->filled('status')) {
            match ($r->status) {
                'pending' => $q->where(fn ($query) => $query->whereNull('live_record.status')->orWhere('live_record.status', 'pending')),
                'in' => $q->whereNotNull('live_record.checked_in_at')->whereNull('live_record.checked_out_at'),
                'out' => $q->whereNotNull('live_record.checked_out_at'),
                default => $q->where('live_record.status', $r->status),
            };
        }
        $sorts = ['name' => 'users.name', 'department' => 'users.department', 'status' => 'live_record.status', 'signin' => 'live_record.checked_in_at'];
        $q->orderBy($sorts[$r->input('sort', 'name')], $r->input('direction', 'asc'))->orderBy('users.id');
        $staff = $q->select('users.*')->paginate($r->integer('per_page', 10), ['users.*'], 'attendance_page')->withQueryString();
        if ($staff->currentPage() > $staff->lastPage()) $staff = $q->paginate($r->integer('per_page', 10), ['users.*'], 'attendance_page', $staff->lastPage())->withQueryString();
        $records = $day ? DailyAttendance::where('programme_id', Access::programme()->id)->where('programme_day_id', $day->id)->whereIn('user_id', $staff->pluck('id'))->get()->keyBy('user_id') : collect();
        return response()->view('daily.live', compact('day', 'staff', 'records', 'counts'))->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $r, ProgrammeDay $day, AttendanceService $service)
    {
        Access::manage('attendance');
        $service->assertDay($day);
        $v = $r->validate(['checkin_opens' => 'nullable|required_if:attendance_required,1|date_format:H:i', 'checkin_closes' => 'nullable|required_if:attendance_required,1|date_format:H:i|after:checkin_opens', 'late_after' => 'nullable|required_if:attendance_required,1|date_format:H:i|after_or_equal:checkin_opens|before_or_equal:checkin_closes', 'checkout_opens' => 'nullable|required_if:attendance_required,1|date_format:H:i|after_or_equal:checkin_opens', 'checkout_closes' => 'nullable|required_if:attendance_required,1|date_format:H:i|after:checkout_opens']);
        $day->update([...$v, 'attendance_required' => $r->boolean('attendance_required'), 'cancelled' => $r->boolean('cancelled')]);
        app(AttendanceSecurity::class)->log('day_policy.updated', true, 'Day '.$day->id.' attendance windows updated.');

        return back()->with('success', 'Daily attendance policy saved. All times are Abuja, Nigeria time.');
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
