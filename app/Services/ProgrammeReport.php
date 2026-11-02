<?php

namespace App\Services;

use App\Models\{Activity, Badge, DailyAttendance, Facilitator, Resource, User};
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
        $staff = Access::staff()->get(['users.id', 'name', 'staff_number', 'active', 'department']);
        $facilitators = $this->people()->where('type', 'facilitator')->count();

        return [
            'staff' => $staff->count(),
            'activated' => $staff->where('active', true)->count(),
            'awaiting' => $staff->where('active', false)->count(),
            'awaiting_people' => $this->listed($staff->where('active', false)->map(fn ($u) => $this->person($u, 'staff'))),
            'facilitators' => $facilitators,
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
        $enrolled = DB::table('enrolments')->where('programme_id', $p->id)->whereIn('user_id', $staffIds)->pluck('created_at', 'user_id');
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

        return ['days' => $rows->all(), 'average' => $rated->isEmpty() ? null : (int) round($rated->avg('rate')), 'today' => $rows->firstWhere('date', now('Africa/Lagos')->toDateString()),
            'gaps' => $days->isEmpty() ? null : $this->attendanceGaps($days->last(), $enrolled)];
    }

    /** The staff behind the latest day's figures: scanned in but not out, out but not in, and not scanned at all. */
    private function attendanceGaps($day, Collection $enrolled): array
    {
        $date = $day->date->toDateString();
        $expected = $enrolled->filter(fn ($at) => ! $at || substr((string) $at, 0, 10) <= $date)->keys();
        $staff = Access::staff()->whereIn('users.id', $expected)->get(['users.id', 'name', 'staff_number', 'department'])->keyBy('id');
        $records = DailyAttendance::where('programme_day_id', $day->id)->whereIn('user_id', $staff->keys())->get(['user_id', 'checked_in_at', 'checked_out_at'])->keyBy('user_id');
        $pick = fn ($test) => $this->listed($staff->filter(fn ($u) => $test($records->get($u->id)))->map(fn ($u) => $this->person($u, 'staff')));

        return [
            'label' => $day->date->format('l j F'),
            'in_only' => $pick(fn ($r) => $r && $r->checked_in_at && ! $r->checked_out_at),
            'out_only' => $pick(fn ($r) => $r && ! $r->checked_in_at && $r->checked_out_at),
            'absent' => $pick(fn ($r) => ! $r || (! $r->checked_in_at && ! $r->checked_out_at)),
        ];
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

    /**
     * Each published activity with how many assigned staff submitted, and the average released score for assessments.
     * Only enrolled staff on the activity count, each person once on their latest attempt, so every figure is out of the same assigned staff.
     */
    private function assessments(): array
    {
        $staffIds = Access::staff()->pluck('users.id');
        $activities = Activity::where('programme_id', Access::programme()->id)->where('published', true)
            ->withCount(['participants' => fn ($q) => $q->whereIn('users.id', $staffIds)])->withSum('questions as total_points', 'points')->latest()->get();
        $attempts = DB::table('submissions')
            ->join('activity_assignments', fn ($j) => $j->on('activity_assignments.activity_id', '=', 'submissions.activity_id')->on('activity_assignments.user_id', '=', 'submissions.user_id'))
            ->whereIn('submissions.activity_id', $activities->modelKeys())->whereIn('submissions.user_id', $staffIds)
            ->orderByDesc('submissions.attempt')->get(['submissions.activity_id', 'submissions.user_id', 'submitted_at', 'released_at', 'score'])->groupBy('activity_id');
        $rows = $activities->map(function (Activity $a) use ($attempts) {
            $mine = $attempts->get($a->id, collect());
            $submitted = $mine->whereNotNull('submitted_at')->unique('user_id')->count();
            $released = $mine->whereNotNull('released_at')->whereNotNull('score')->unique('user_id');
            $total = (float) $a->total_points;

            return ['title' => $a->title, 'kind' => ucfirst($a->label()), 'assigned' => $a->participants_count, 'submitted' => $submitted,
                'outstanding' => max(0, $a->participants_count - $submitted), 'released' => $released->count(),
                'average' => $a->kind === 'quiz' && $released->isNotEmpty() && $total ? (int) round($released->avg('score') / $total * 100) : null];
        })->values();
        $assigned = $rows->sum('assigned');

        return [
            'rows' => $rows->all(),
            'published' => $rows->count(),
            'rate' => $assigned ? (int) round($rows->sum('submitted') / $assigned * 100) : null,
            'average' => $rows->whereNotNull('average')->isEmpty() ? null : (int) round($rows->whereNotNull('average')->avg('average')),
        ];
    }

    /**
     * Badges for staff, facilitators and admins, by type. Badges left from people no longer in the programme are not counted,
     * so for each type the people with a badge plus those without add up to its people.
     */
    private function badges(): array
    {
        $badges = Badge::where('programme_id', Access::programme()->id)->get(['subject_key', 'printed_at', 'collected_at'])->keyBy('subject_key');
        $people = $this->people();
        $byType = $people->groupBy('type');
        $types = collect(['staff', 'facilitator', 'admin'])->filter(fn ($type) => $byType->has($type))->map(function ($type) use ($byType, $badges) {
            $people = $byType[$type];
            $held = $people->map(fn ($person) => $badges->get($person['key']))->filter();

            return ['label' => ucfirst($type), 'people' => $people->count(), 'generated' => $held->count(), 'without' => $people->count() - $held->count(),
                'printed' => $held->whereNotNull('printed_at')->count(), 'collected' => $held->whereNotNull('collected_at')->count()];
        })->values();

        return [
            'people' => $types->sum('people'),
            'without' => $types->sum('without'),
            'stages' => [
                ['label' => 'Generated', 'value' => $types->sum('generated')],
                ['label' => 'Printed', 'value' => $types->sum('printed')],
                ['label' => 'Collected', 'value' => $types->sum('collected')],
            ],
            'types' => $types->all(),
            // Everyone who has not collected a badge, by how far their badge got.
            'gaps' => [
                'without' => $this->listed($people->filter(fn ($person) => ! $badges->has($person['key']))),
                'unprinted' => $this->listed($people->filter(fn ($person) => $badges->has($person['key']) && ! $badges[$person['key']]->printed_at)),
                'uncollected' => $this->listed($people->filter(fn ($person) => ($badge = $badges->get($person['key'])) && $badge->printed_at && ! $badge->collected_at)),
            ],
        ];
    }

    /** Everyone in the programme who can hold a badge: enrolled accounts, and facilitator profiles without one. */
    private function people(): Collection
    {
        $p = Access::programme();
        $users = User::whereIn('role', User::ROLES)->whereHas('programmes', fn ($q) => $q->where('programmes.id', $p->id))->get(['id', 'name', 'role', 'staff_number', 'department'])
            ->map(fn ($u) => $this->person($u, $u->role) + ['key' => 'u:'.$u->id]);
        $profiles = Facilitator::where('programme_id', $p->id)->whereNull('user_id')->get(['id', 'name', 'department', 'organisation'])
            ->map(fn ($f) => $this->person($f, 'facilitator') + ['key' => 'f:'.$f->id]);

        return $users->concat($profiles);
    }

    /** A person as listed under a figure: name, type, and what identifies them. */
    private function person(User|Facilitator $person, string $type): array
    {
        $detail = $person instanceof User ? [$person->staff_number, $person->department] : [$person->department, $person->organisation];

        return ['name' => $person->name, 'type' => $type, 'detail' => implode(' · ', array_filter(array_map('trim', array_map('strval', $detail))))];
    }

    private function listed(Collection $people): array
    {
        return $people->sortBy(fn ($person) => mb_strtolower($person['name']))->map(fn ($person) => array_intersect_key($person, array_flip(['name', 'type', 'detail'])))->values()->all();
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
