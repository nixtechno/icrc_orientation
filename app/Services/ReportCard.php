<?php

namespace App\Services;

use App\Models\{Activity, DailyAttendance, ProgrammeDay, ProgrammeSession, Submission, User};
use Illuminate\Support\Collection;

/**
 * Gathers one person's record for their printable report card: attendance and assessment scores for each programme
 * day, and their assignments and presentations. It only reports what is recorded; it does not grade.
 */
class ReportCard
{
    public function for(User $user): array
    {
        $programme = Access::programme();
        $zone = $programme->timezone;
        $today = now($zone)->toDateString();
        $days = app(AttendanceService::class)->days()->with('week')->where('cancelled', false)
            ->whereBetween('date', [$programme->starts_on->toDateString(), $programme->ends_on->toDateString()])->orderBy('date')->get();
        $records = DailyAttendance::where('programme_id', $programme->id)->where('user_id', $user->id)->get()->keyBy('programme_day_id');

        $activities = Activity::where('programme_id', $programme->id)->where('published', true)
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))->with('questions')->get();
        $mine = Submission::whereIn('activity_id', $activities->modelKeys())->where('user_id', $user->id)->whereNotNull('submitted_at')->get()->groupBy('activity_id');
        $sessionDays = ProgrammeSession::whereIn('id', $activities->pluck('session_id')->filter())->pluck('programme_day_id', 'id');
        // An activity belongs to the day of its timetable session, or else the day it is due.
        $dayOf = fn (Activity $a) => $sessionDays[$a->session_id] ?? $days->first(fn ($d) => $a->due_at && $d->date->toDateString() === $a->due_at->timezone($zone)->toDateString())?->id;

        $quizzes = $activities->where('kind', 'quiz')->sortBy(fn ($a) => $a->due_at)->values()->map(function (Activity $a) use ($mine, $dayOf, $days) {
            $best = $mine->get($a->id)?->whereNotNull('score')->sortByDesc('score')->first();
            $submitted = $mine->has($a->id);
            // Everyone's best marked score, for the cohort average.
            $cohort = Submission::where('activity_id', $a->id)->whereNotNull('submitted_at')->whereNotNull('score')->selectRaw('user_id, MAX(score) as best')->groupBy('user_id')->pluck('best');
            $dayId = $dayOf($a);

            return [
                'activity' => $a, 'day' => $days->firstWhere('id', $dayId), 'total' => (float) $a->questions->sum('points'),
                'score' => $best ? (float) $best->score : null,
                'status' => $best ? 'marked' : ($submitted ? 'awaiting' : ($a->due_at && $a->due_at->isPast() ? 'missed' : 'open')),
                'cohort' => $cohort->isNotEmpty() ? round($cohort->avg(), 1) : null,
            ];
        });
        $marked = $quizzes->where('status', 'marked');
        $assessmentAverage = $marked->sum('total') > 0 ? round(100 * $marked->sum('score') / $marked->sum('total'), 1) : null;

        $work = $activities->whereIn('kind', ['assignment', 'presentation'])->sortBy(fn ($a) => $a->due_at)->values()->map(function (Activity $a) use ($mine) {
            $latest = $mine->get($a->id)?->sortByDesc('submitted_at')->first();

            return ['activity' => $a, 'submission' => $latest, 'status' => $latest ? ($latest->score !== null ? 'marked' : 'awaiting') : ($a->due_at && $a->due_at->isPast() ? 'missed' : 'open')];
        });

        $byDay = $quizzes->filter(fn ($q) => $q['day'])->groupBy(fn ($q) => $q['day']->id);
        $grid = $days->groupBy(fn ($d) => $d->week?->number ?? 0)->map(fn (Collection $week) => $week->map(function (ProgrammeDay $d) use ($records, $byDay, $today) {
            $record = $records->get($d->id);
            $past = $d->date->toDateString() < $today || ($d->date->toDateString() === $today && $record);
            $status = in_array($record?->status, ['present', 'late', 'excused', 'absent'], true) ? $record->status
                : ($past && $d->attendance_required ? 'absent' : ($d->date->toDateString() > $today ? 'upcoming' : 'none'));
            $quizzes = $byDay->get($d->id, collect());
            $marked = $quizzes->where('status', 'marked');

            return ['day' => $d, 'attendance' => $status, 'quizzes' => $quizzes,
                'score' => $marked->isNotEmpty() ? $marked->sum('score') : null, 'total' => $marked->isNotEmpty() ? $marked->sum('total') : null,
                'quiz' => $quizzes->isEmpty() ? 'none' : ($marked->isNotEmpty() ? 'marked' : ($quizzes->contains('status', 'awaiting') ? 'awaiting' : ($quizzes->contains('status', 'missed') ? 'missed' : 'open')))];
        }));
        $counted = $grid->flatten(1)->countBy('attendance');
        $tally = collect(['present', 'late', 'excused', 'absent'])->mapWithKeys(fn ($s) => [$s => $counted->get($s, 0)]);

        return [
            'programme' => $programme, 'user' => $user, 'days' => $days, 'grid' => $grid, 'tally' => $tally,
            'attendance' => app(AttendanceService::class)->summary($user), 'quizzes' => $quizzes, 'assessmentAverage' => $assessmentAverage,
            'work' => $work, 'generatedAt' => now($zone),
        ];
    }
}
