<?php

namespace App\Services;

use App\Models\DailyAttendance;

class AttendanceTrend
{
    public function data(): array
    {
        $programme = Access::programme();
        $days = app(AttendanceService::class)->days()->where('attendance_required', true)->where('cancelled', false)
            ->whereBetween('date', [$programme->starts_on->toDateString(), $programme->ends_on->toDateString()])
            ->whereDate('date', '<=', today($programme->timezone)->toDateString())->orderByDesc('date')->limit(10)->get()->reverse()->values();
        $records = DailyAttendance::where('programme_id', $programme->id)->whereIn('programme_day_id', $days->pluck('id'))
            ->whereIn('user_id', Access::staff()->select('users.id'))->get()->groupBy('programme_day_id');
        return $days->map(function ($day) use ($records) {
            $rows = $records->get($day->id, collect());
            return ['date' => $day->date->format('j M'), 'full_date' => $day->date->format('j F Y'),
                'present' => $rows->where('status', 'present')->unique('user_id')->count(),
                'late' => $rows->where('status', 'late')->unique('user_id')->count()];
        })->all();
    }
}
