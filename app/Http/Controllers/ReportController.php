<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DailyAttendance;
use App\Models\ProgrammeSession;
use App\Models\Submission;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    public function index(Request $r)
    {
        Access::manage('reports');
        $tab = $r->query('tab') === 'exports' ? 'exports' : 'report';
        if ($tab === 'exports') {
            return view('reports', compact('tab'));
        }
        $merge = app(\App\Services\PersonMerge::class);
        $duplicates = $merge->candidates()->map(fn ($group) => [
            'signature' => $merge->signature($group),
            'people' => $group->map(fn (\App\Models\User $u) => [
                'user' => $u,
                'badge' => \App\Models\Badge::where('user_id', $u->id)->first(['printed_at', 'collected_at']),
                'attendance' => DailyAttendance::where('user_id', $u->id)->count(),
                'submissions' => Submission::where('user_id', $u->id)->whereNotNull('submitted_at')->count(),
                'enrolled' => $u->programmes()->where('programmes.id', Access::programme()->id)->exists(),
            ])->sortByDesc(fn ($p) => [(int) $p['user']->active, $p['attendance'] + $p['submissions'], (int) (bool) $p['badge']?->printed_at])->values(),
        ])->values();

        return view('reports', ['tab' => $tab, 'report' => app(\App\Services\ProgrammeReport::class)->data(), 'duplicates' => $duplicates,
            'canMerge' => $r->user()->role === 'admin' && ! \App\Services\AttendanceSync::venue()]);
    }

    /** Merges every other profile in a duplicate group into the one chosen to keep. */
    public function mergeDuplicates(Request $r, \App\Services\PersonMerge $merge)
    {
        $this->reconciler();
        $v = $r->validate(['signature' => 'required|string|max:2000', 'keep' => 'required|integer', 'confirmed' => 'accepted'], ['confirmed.accepted' => 'Tick the box to confirm the merge.']);
        $group = $merge->candidates()->first(fn ($group) => $merge->signature($group) === $v['signature']);
        abort_unless($group && $group->contains('id', (int) $v['keep']), 422, 'These profiles have changed since the page loaded. Reload and try again.');
        $keep = $group->firstWhere('id', (int) $v['keep']);
        foreach ($group->reject(fn ($u) => $u->is($keep)) as $drop) {
            $keep = $merge->merge($keep, $drop);
        }

        return redirect('/reports#duplicates')->with('success', 'Merged into '.$keep->name.' ('.$keep->email.'). The other '.($group->count() === 2 ? 'profile was' : 'profiles were').' removed.');
    }

    /** Records that a group of matching names are different people, so it stops being suggested. */
    public function dismissDuplicates(Request $r, \App\Services\PersonMerge $merge)
    {
        $this->reconciler();
        $signature = $r->validate(['signature' => 'required|string|max:2000'])['signature'];
        abort_unless($merge->candidates()->contains(fn ($group) => $merge->signature($group) === $signature), 422, 'These profiles have changed since the page loaded. Reload and try again.');
        $p = Access::programme();
        $p->update(['settings' => [...($p->settings ?? []), 'distinct_people' => array_values(array_unique([...($p->settings['distinct_people'] ?? []), $signature]))]]);
        Access::audit('user.duplicates_dismissed', $signature);

        return redirect('/reports#duplicates')->with('success', 'Marked as different people. They will not be suggested again.');
    }

    /** Merging changes accounts, so only administrators can do it, and only on the live site that venues copy from. */
    private function reconciler(): void
    {
        Access::manage('reports');
        abort_unless(auth()->user()->role === 'admin', 403);
        abort_if(\App\Services\AttendanceSync::venue(), 422, 'Reconcile duplicates on the live site. Merges reach this venue on the next sync.');
    }

    public function export(Request $r)
    {
        Access::manage($r->input('report') === 'attendance' ? 'attendance' : 'reports');
        $v = $r->validate(['report' => 'required|in:staff,attendance,assessments,progress,schedule,activation', 'format' => 'nullable|in:csv,xlsx,pdf', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'week' => 'nullable|integer|between:1,6', 'department' => 'nullable|string|max:255', 'group_name' => 'nullable|string|max:255', 'staff' => 'nullable|string|max:120', 'status' => 'nullable|string|max:30', 'attendance_mode' => 'nullable|in:daily,individual,department,late,incomplete,summary']);
        $staff = Access::staff();
        foreach (['department', 'group_name'] as $key) {
            if (! empty($v[$key])) {
                $staff->where($key, $v[$key]);
            }
        }if (! empty($v['staff'])) {
            ctype_digit((string) $v['staff']) ? $staff->whereKey($v['staff']) : $staff->where('slug', $v['staff']);
        }$users = $staff->get();
        $rows = [];
        $headers = [];
        switch ($v['report']) {
            case 'staff':case 'activation':$headers = ['Name', 'Email', 'Staff number', 'Department', 'Group', 'Active'];
                foreach ($users as $u) {
                    $rows[] = [$u->name, $u->email, $u->staff_number, $u->department, $u->group_name, $u->active ? 'Yes' : 'No'];
                }break;
            case 'progress':$headers = ['Name', 'Present or late days', 'Eligible elapsed days', 'Pending decisions', 'Daily attendance %', 'Submitted work', 'Released results'];
                foreach ($users as $u) {
                    $s = app(AttendanceService::class)->summary($u);
                    $q = Submission::where('user_id', $u->id)->whereHas('activity', fn ($q) => $q->where('programme_id', Access::programme()->id));
                    $rows[] = [$u->name, $s['numerator'], $s['denominator'], $s['pending'], $s['percentage'], (clone $q)->whereNotNull('submitted_at')->count(), (clone $q)->whereNotNull('released_at')->count()];
                }break;
            case 'schedule':$headers = ['Date', 'Start', 'End', 'Title', 'Facilitator source label', 'Type', 'Status'];
                $q = ProgrammeSession::with('day')->whereHas('day.week', fn ($q) => $q->where('programme_id', Access::programme()->id));
                $this->dateFilters($q, $v, 'session');
                foreach ($q->orderBy('programme_day_id')->orderBy('id')->get() as $s) {
                    $rows[] = [$s->day->date->toDateString(), $s->starts_at?->format('H:i'), $s->ends_at?->format('H:i'), $s->title ?? 'To be confirmed', $s->facilitator ?? 'To be confirmed', $s->type, $s->status];
                }break;
            case 'attendance':
                $mode = $v['attendance_mode'] ?? 'daily';
                $headers = ['Staff ID', 'Name', 'Department', 'Programme day', 'Week', 'Date', 'Status', 'Punctuality', 'Sign in (Lagos)', 'Sign out (Lagos)', 'Duration seconds', 'Device in', 'Device out', 'Location in', 'Location out', 'Notes', 'Sign-in method', 'Sign-out method'];
                $q = app(AttendanceService::class)->days()->whereBetween('date', [Access::programme()->starts_on->toDateString(), Access::programme()->ends_on->toDateString()])->with('week')->where('attendance_required', true)->where('cancelled', false)->whereDate('date', '<=', now('Africa/Lagos')->toDateString());
                $this->dateFilters($q, $v, 'day');
                $days = $q->orderBy('date')->get();
                $records = DailyAttendance::where('programme_id', Access::programme()->id)->whereIn('user_id', $users->pluck('id'))->get()->keyBy(fn ($x) => $x->user_id.':'.$x->programme_day_id);
                if ($mode === 'summary') {
                    $headers = ['Staff ID', 'Name', 'Department', 'Eligible elapsed days', 'Present', 'Absent', 'Late', 'Attendance %'];
                }
                $enrolled = DB::table('enrolments')->where('programme_id', Access::programme()->id)->pluck('created_at', 'user_id');
                foreach ($users as $u) {
                    $total = 0;
                    $present = 0;
                    $late = 0;
                    foreach ($days as $day) {
                        if (isset($enrolled[$u->id]) && $day->date->toDateString() < substr($enrolled[$u->id], 0, 10)) {
                            continue;
                        }
                        $record = $records->get($u->id.':'.$day->id);
                        $close = app(AttendanceService::class)->time($day, 'checkin_closes');
                        $elapsed = $close && now()->gte($close);
                        $status = $record?->status ?? ($elapsed ? 'absent' : 'pending');
                        if ($mode === 'summary') {
                            if ($elapsed && ! ($status === 'excused' && ! (Access::programme()->settings['excused_in_denominator'] ?? false))) {
                                $total++;
                                if (in_array($status, ['present', 'late'])) {
                                    $present++;
                                } if ($status === 'late') {
                                    $late++;
                                }
                            }

                            continue;
                        }
                        if (! empty($v['status']) && $status !== $v['status']) {
                            continue;
                        }
                        if ($mode === 'late' && $status !== 'late') {
                            continue;
                        }
                        if ($mode === 'incomplete' && (! $record?->checked_in_at || $record?->checked_out_at)) {
                            continue;
                        }
                        $rows[] = [$record?->staff_number_snapshot ?? $u->staff_number, $record?->staff_name ?? $u->name, $u->department, $day->number, $day->week->number, $day->date->toDateString(), $status, $record?->punctuality_status, $record?->checked_in_at?->timezone('Africa/Lagos')->format('Y-m-d H:i:s'), $record?->checked_out_at?->timezone('Africa/Lagos')->format('Y-m-d H:i:s'), $record?->duration_seconds, $record?->signin_device_verified ? 'Verified' : 'Unverified', $record?->signout_device_verified ? 'Verified' : 'Unverified', $record?->signin_location_verified ? 'Verified' : 'Unverified', $record?->signout_location_verified ? 'Verified' : 'Unverified', $record?->notes, $record?->signin_method ?? ($record?->signin_device_verified ? 'device' : null), $record?->signout_method ?? ($record?->signout_device_verified ? 'device' : null)];
                    }
                    if ($mode === 'summary') {
                        $rows[] = [$u->staff_number, $u->name, $u->department, $total, $present, max(0, $total - $present), $late, $total ? round(100 * $present / $total, 1) : null];
                    }
                }
                break;
            case 'assessments':$headers = ['Name', 'Activity', 'Kind', 'Attempt', 'Status', 'Submitted', 'Score', 'Released', 'Feedback'];
                $q = Activity::where('programme_id', Access::programme()->id)->with(['participants' => fn ($q) => $q->whereIn('users.id', $users->pluck('id')), 'submissions' => fn ($q) => $q->whereIn('user_id', $users->pluck('id'))]);
                if (! empty($v['week'])) {
                    $q->where(function ($query) use ($v) {
                        $weekIds = Access::programme()->weeks()->where('number', $v['week'])->pluck('id');
                        $query->whereIn('programme_week_id', $weekIds)->orWhereIn('session_id', ProgrammeSession::whereHas('day', fn ($q) => $q->whereIn('programme_week_id', $weekIds))->pluck('id'));
                    });
                }foreach ($q->get() as $a) {
                    foreach ($a->participants as $u) {
                        $attempts = $a->submissions->where('user_id', $u->id);
                        if ($attempts->isEmpty()) {
                            if (empty($v['from']) && empty($v['to']) && in_array($v['status'] ?? '', ['', 'pending'])) {
                                $rows[] = [$u->name, $a->title, $a->kind, null, 'Not started', null, null, null, null];
                            }

                            continue;
                        }foreach ($attempts as $s) {
                            if (! empty($v['from']) && (! $s->submitted_at || $s->submitted_at->toDateString() < $v['from'])) {
                                continue;
                            }if (! empty($v['to']) && (! $s->submitted_at || $s->submitted_at->toDateString() > $v['to'])) {
                                continue;
                            }if (($v['status'] ?? '') === 'pending' && $s->released_at) {
                                continue;
                            }if (($v['status'] ?? '') === 'released' && ! $s->released_at) {
                                continue;
                            }$rows[] = [$u->name, $a->title, $a->kind, $s->attempt, $s->released_at ? 'Released' : ($s->submitted_at ? 'Awaiting release' : 'Started'), $s->submitted_at?->format('Y-m-d H:i:s'), $s->score, $s->released_at?->format('Y-m-d H:i:s'), $s->feedback];
                        }
                    }
                }break;
        }
        Access::audit('report.export', $v['report'], null, $v);
        $format = $v['format'] ?? 'csv';
        if ($r->boolean('print')) {
            return view('report-print', compact('headers', 'rows', 'v'));
        }if ($format === 'pdf') {
            $bytes = app(PdfService::class)->render(view('report-pdf', compact('headers', 'rows', 'v'))->render(), $v['report'] === 'attendance' ? [0, 0, 1190.55, 841.89] : null);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="icrc-report.pdf"', 'Cache-Control' => 'private, no-store']);
        }
        if ($format === 'xlsx') {
            $sheet = new Spreadsheet;
            $tab = $sheet->getActiveSheet();
            $all = [['ICRC report', $v['report'], 'Generated', now()->toIso8601String()], ['Filters', json_encode($v)], $headers, ...$rows];
            foreach ($all as $ri => $row) {
                foreach ($row as $ci => $value) {
                    $tab->setCellValueExplicit([$ci + 1, $ri + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }

            return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), 'icrc-'.$v['report'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($headers, $rows, $v) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['ICRC programme report', $v['report'], 'Generated', now()->toIso8601String()]);
            fputcsv($h, ['Filters', json_encode($v)]);
            fputcsv($h, $headers);
            foreach ($rows as $row) {
                fputcsv($h, array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $row));
            }fclose($h);
        }, 'icrc-'.$v['report'].'.csv', ['Content-Type' => 'text/csv']);
    }

    private function dateFilters($q, array $v, string $type): void
    {
        if ($type === 'day') {
            if (! empty($v['from'])) {
                $q->whereDate('date', '>=', $v['from']);
            }if (! empty($v['to'])) {
                $q->whereDate('date', '<=', $v['to']);
            }if (! empty($v['week'])) {
                $q->whereHas('week', fn ($q) => $q->where('number', $v['week']));
            }
        } else {
            if (! empty($v['from'])) {
                $q->whereHas('day', fn ($q) => $q->where('date', '>=', $v['from']));
            }if (! empty($v['to'])) {
                $q->whereHas('day', fn ($q) => $q->where('date', '<=', $v['to']));
            }if (! empty($v['week'])) {
                $q->whereHas('day.week', fn ($q) => $q->where('number', $v['week']));
            }
        }
    }
}
