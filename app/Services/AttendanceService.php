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
