<?php

namespace App\Services;

use App\Models\DailyAttendance;
use App\Models\ProgrammeDay;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function days()
    {
        return ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', Access::programme()->id));
    }

    public function time(ProgrammeDay $d, string $f): ?Carbon
    {
        return $d->$f ? Carbon::parse($d->date->toDateString().' '.$d->$f, 'Africa/Lagos') : null;
    }

    /** Every programme day takes attendance for the whole day with no late time; cancelled days are left alone. */
    public function takeAttendanceAllDay(): int
    {
        $whole = ['attendance_required' => true, 'checkin_opens' => '00:00', 'checkin_closes' => '23:59', 'late_after' => null, 'checkout_opens' => '00:00', 'checkout_closes' => '23:59'];
        $days = $this->days()->where('cancelled', false)->get();
        foreach ($days as $day) {
            $before = $day->only(array_keys($whole));
            $day->update($whole);
            Access::audit('day_policy.whole_day', (string) $day->id, $before, $whole);
        }

        return $days->count();
    }

    /**
     * Uses badge print times as sign-ins for a day when staff received their badge on arrival. A staff member's
     * record gets the print time as its sign-in when it has none or a later one, and staff with a printed badge but
     * no record get one. Earlier real sign-ins are kept.
     *
     * @return array{updated: int, created: int, unchanged: int}
     */
    public function signInFromBadgePrints(ProgrammeDay $day, bool $save = true): array
    {
        $date = $day->date->toDateString();
        $counts = ['updated' => 0, 'created' => 0, 'unchanged' => 0];
        $badges = \App\Models\Badge::where('programme_id', Access::programme()->id)->whereNotNull('printed_at')->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->where('role', 'staff')->whereHas('programmes', fn ($p) => $p->where('programmes.id', Access::programme()->id)))
            ->with('user')->get()->filter(fn ($b) => $b->printed_at->copy()->timezone('Africa/Lagos')->toDateString() === $date);
        foreach ($badges as $badge) {
            $printed = $badge->printed_at;
            $record = DailyAttendance::where('programme_id', Access::programme()->id)->where('programme_day_id', $day->id)->where('user_id', $badge->user_id)->first();
            if ($record?->checked_in_at && $record->checked_in_at->lte($printed)) {
                $counts['unchanged']++;
                continue;
            }
            $counts[$record ? 'updated' : 'created']++;
            if (! $save) continue;
            $record ??= new DailyAttendance(['programme_id' => Access::programme()->id, 'programme_day_id' => $day->id, 'user_id' => $badge->user_id,
                'staff_name' => $badge->user->name, 'staff_number_snapshot' => $badge->user->staff_number, 'attendance_date' => $day->date]);
            $late = $this->time($day, 'late_after');
            $record->fill(['checked_in_at' => $printed, 'signin_method' => 'badge_print', 'signin_scanned_by' => null, 'signin_device_verified' => false, 'signin_location_verified' => false,
                'status' => in_array($record->status, [null, 'pending', 'present', 'late'], true) ? ($late && $printed->gt($late) ? 'late' : 'present') : $record->status,
                'punctuality_status' => $late ? ($printed->gt($late) ? 'Late' : 'On Time') : null,
                'duration_seconds' => $record->checked_out_at ? max(0, (int) $printed->diffInSeconds($record->checked_out_at)) : null])->save();
        }

        return $counts;
    }

    /** Staff records on a day with a sign-out scan but no sign-in, such as people whose badge print was never recorded. */
    public function signedOutOnly(ProgrammeDay $day): \Illuminate\Support\Collection
    {
        return DailyAttendance::where('programme_id', Access::programme()->id)->where('programme_day_id', $day->id)->whereNull('checked_in_at')->whereNotNull('checked_out_at')
            ->whereIn('user_id', Access::staff()->select('users.id'))->orderBy('staff_name')->get();
    }

    /** Gives the chosen sign-out-only records a sign-in at the time an administrator confirms, never after their sign-out. */
    public function signInSignedOutOnly(ProgrammeDay $day, array $ids, Carbon $at): int
    {
        $late = $this->time($day, 'late_after');
        $records = $this->signedOutOnly($day)->whereIn('id', $ids);
        foreach ($records as $record) {
            $in = $at->min($record->checked_out_at);
            $record->fill(['checked_in_at' => $in, 'signin_method' => 'manual', 'signin_scanned_by' => auth()->id(), 'signin_device_verified' => false, 'signin_location_verified' => false,
                'status' => in_array($record->status, [null, 'pending', 'present', 'late'], true) ? ($late && $in->gt($late) ? 'late' : 'present') : $record->status,
                'punctuality_status' => $late ? ($in->gt($late) ? 'Late' : 'On Time') : null,
                'duration_seconds' => max(0, (int) $in->diffInSeconds($record->checked_out_at))])->save();
            Access::audit('attendance.signin_added', (string) $record->id, null, ['user_id' => $record->user_id, 'checked_in_at' => $in->toIso8601String()]);
        }

        return $records->count();
    }

    public function assertDay(ProgrammeDay $d): void
    {
        abort_unless($d->week->programme_id === Access::programme()->id, 404);
    }

    public function summary(User $u): array
    {
        $p = Access::programme();
        $records = DailyAttendance::where('programme_id', $p->id)->where('user_id', $u->id)->get()->keyBy('programme_day_id');
        $e = DB::table('enrolments')->where('programme_id', $p->id)->where('user_id', $u->id)->first();
        $num = 0;
        $den = 0;
        $pending = 0;
        if ($e) {
            foreach ($this->days()->whereBetween('date', [$p->starts_on->toDateString(), $p->ends_on->toDateString()])->where('attendance_required', true)->where('cancelled', false)->whereDate('date', '<=', now('Africa/Lagos')->toDateString())->get() as $d) {
                if ($e->created_at && $d->date->toDateString() < Carbon::parse($e->created_at)->toDateString()) {
                    continue;
                }$close = $this->time($d, 'checkin_closes');
                if (! $close || now()->lt($close)) {
                    continue;
                }$r = $records->get($d->id);
                if ($r?->status === 'excused' && ! ($p->settings['excused_in_denominator'] ?? false)) {
                    continue;
                }$den++;
                if (in_array($r?->status, ['present', 'late'], true)) {
                    $num++;
                } elseif (! $r || $r->status === 'pending') {
                    $pending++;
                }
            }
        }

        return ['numerator' => $num, 'denominator' => $den, 'pending' => $pending, 'absent' => max(0, $den - $num), 'late' => $records->where('status', 'late')->count(), 'required' => $this->days()->whereBetween('date', [$p->starts_on->toDateString(), $p->ends_on->toDateString()])->where('attendance_required', true)->where('cancelled', false)->count(), 'percentage' => $den ? round(100 * $num / $den, 1) : null];
    }
}
