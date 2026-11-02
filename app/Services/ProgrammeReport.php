<?php

namespace App\Services;

use App\Models\{Activity, Badge, DailyAttendance, Resource, User};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Figures for the programme report: registration, attendance, learning materials, assessments and badges. */
class ProgrammeReport
{
    public function data(): array
    {
        return [
            'registration' => $this->registration(),
            'attendance' => $this->attendance(),
            'materials' => $this->materials(),
            'assessments' => $this->assessments(),
            'badges' => $this->badges(),
        ];
    }

    private function registration(): array
    {
        $staff = Access::staff()->get(['users.id', 'active', 'department']);
        $facilitators = User::where('role', 'facilitator')->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id))->count();

        return [
            'staff' => $staff->count(),
            'activated' => $staff->where('active', true)->count(),
            'awaiting' => $staff->where('active', false)->count(),
            'facilitators' => $facilitators,
            'departments' => $this->ranked($staff->countBy(fn ($u) => trim((string) $u->department) ?: 'Not set'), 8),
        ];
    }

    /**
     * One row per elapsed programme day that takes attendance or has scans: staff expected, staff who attended
     * (any sign-in or sign-out scan), and sign-ins and sign-outs separately. Every scan counts, whatever the day's settings.
     */
    private function attendance(): array
    {
        $p = Access::programme();
        $staffIds = Access::staff()->pluck('users.id');
        $enrolled = DB::table('enrolments')->where('programme_id', $p->id)->whereIn('user_id', $staffIds)->pluck('created_at');
        $records = DailyAttendance::where('programme_id', $p->id)->whereIn('user_id', $staffIds)->toBase()
            ->selectRaw('programme_day_id, sum(checked_in_at is not null or checked_out_at is not null) attended, sum(checked_in_at is not null) signed_in, sum(checked_out_at is not null) signed_out')
            ->groupBy('programme_day_id')->get()->keyBy('programme_day_id');
        $days = app(AttendanceService::class)->days()->where('cancelled', false)
            ->where(fn ($q) => $q->where('attendance_required', true)->orWhereIn('id', $records->keys()))
            ->whereBetween('date', [$p->starts_on->toDateString(), min($p->ends_on->toDateString(), now('Africa/Lagos')->toDateString())])
            ->orderBy('date')->get();
        $rows = $days->map(function ($day) use ($records, $enrolled) {
            $date = $day->date->toDateString();
            $expected = $enrolled->filter(fn ($at) => ! $at || substr((string) $at, 0, 10) <= $date)->count();
            $counts = $records->get($day->id);
            $attended = (int) ($counts->attended ?? 0);

            return ['date' => $date, 'label' => $day->date->format('D j M'), 'expected' => $expected, 'attended' => $attended,
                'signed_in' => (int) ($counts->signed_in ?? 0), 'signed_out' => (int) ($counts->signed_out ?? 0),
                'rate' => $expected ? (int) round($attended / $expected * 100) : null];
        })->values();
        $rated = $rows->whereNotNull('rate');

        return ['days' => $rows->all(), 'average' => $rated->isEmpty() ? null : (int) round($rated->avg('rate')), 'today' => $rows->firstWhere('date', now('Africa/Lagos')->toDateString())];
    }

    private function materials(): array
    {
        $items = Resource::where('programme_id', Access::programme()->id)->get(['published', 'category', 'week']);
        $published = $items->where('published', true);

        return [
            'total' => $items->count(),
            'published' => $published->count(),
            'categories' => $this->ranked($published->countBy(fn ($r) => trim((string) $r->category) ?: 'Uncategorised'), 8),
            'weeks' => $published->countBy(fn ($r) => $r->week ? 'Week '.$r->week : 'No week')->sortKeys()->map(fn ($count, $label) => ['label' => $label, 'value' => $count])->values()->all(),
        ];
    }

    /** Each published activity with how many assigned staff submitted, and the average released score for assessments. */
    private function assessments(): array
    {
        $activities = Activity::where('programme_id', Access::programme()->id)->where('published', true)
            ->withCount('participants')->withSum('questions as total_points', 'points')->latest()->get();
        $submitted = DB::table('submissions')->whereIn('activity_id', $activities->modelKeys())->whereNotNull('submitted_at')
            ->selectRaw('activity_id, count(distinct user_id) people')->groupBy('activity_id')->pluck('people', 'activity_id');
        $scores = DB::table('submissions')->whereIn('activity_id', $activities->modelKeys())->whereNotNull('released_at')->whereNotNull('score')
            ->selectRaw('activity_id, avg(score) average, count(*) released')->groupBy('activity_id')->get()->keyBy('activity_id');
        $rows = $activities->map(function (Activity $a) use ($submitted, $scores) {
            $done = min((int) ($submitted[$a->id] ?? 0), $a->participants_count);
            $score = $scores->get($a->id);
            $total = (float) $a->total_points;

            return ['title' => $a->title, 'kind' => ucfirst($a->label()), 'assigned' => $a->participants_count, 'submitted' => $done,
                'outstanding' => max(0, $a->participants_count - $done), 'released' => (int) ($score->released ?? 0),
                'average' => $a->kind === 'quiz' && $score && $total ? (int) round($score->average / $total * 100) : null];
        })->values();
        $assigned = $rows->sum('assigned');

        return [
            'rows' => $rows->all(),
            'published' => $rows->count(),
            'rate' => $assigned ? (int) round($rows->sum('submitted') / $assigned * 100) : null,
            'average' => $rows->whereNotNull('average')->isEmpty() ? null : (int) round($rows->whereNotNull('average')->avg('average')),
        ];
    }

    private function badges(): array
    {
        $p = Access::programme();
        $badges = Badge::where('programme_id', $p->id)->get(['user_id', 'category', 'printed_at', 'collected_at', 'materials_collected_at']);
        $people = User::whereIn('role', User::ROLES)->whereHas('programmes', fn ($q) => $q->where('programmes.id', $p->id))->pluck('id');

        return [
            'people' => $people->count(),
            'without' => $people->diff($badges->pluck('user_id')->filter())->count(),
            'stages' => [
                ['label' => 'Generated', 'value' => $badges->count()],
                ['label' => 'Printed', 'value' => $badges->whereNotNull('printed_at')->count()],
                ['label' => 'Collected', 'value' => $badges->whereNotNull('collected_at')->count()],
                ['label' => 'Materials collected', 'value' => $badges->whereNotNull('materials_collected_at')->count()],
            ],
            'categories' => $badges->countBy('category')->map(fn ($count, $category) => ['label' => ucfirst($category), 'value' => $count])->values()->all(),
        ];
    }

    /** Largest first, with everything past the limit folded into "Other". */
    private function ranked(Collection $counts, int $limit): array
    {
        $sorted = $counts->sortDesc();
        $rows = $sorted->take($limit)->map(fn ($count, $label) => ['label' => (string) $label, 'value' => $count])->values();
        if ($sorted->count() > $limit) {
            $rows->push(['label' => 'Other', 'value' => $sorted->slice($limit)->sum()]);
        }

        return $rows->all();
    }
}
