<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DailyAttendance extends Model
{
    protected $table = 'daily_attendance';

    protected $guarded = [];

    protected $hidden = ['signin_location', 'signout_location'];

    protected static function booted(): void
    {
        static::creating(function (DailyAttendance $record): void {
            $record->sync_uuid ??= (string) Str::uuid();
            $record->origin ??= config('attendance_sync.origin');
        });
        static::saving(function (DailyAttendance $record): void {
            if (! $record->exists || $record->isDirty()) {
                $record->sync_version = (int) $record->sync_version + 1;
            }
        });
    }

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'attendance_date' => 'date', 'signin_location' => 'encrypted:array', 'signout_location' => 'encrypted:array', 'signin_device_verified' => 'boolean', 'signout_device_verified' => 'boolean', 'signin_location_verified' => 'boolean', 'signout_location_verified' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function day()
    {
        return $this->belongsTo(ProgrammeDay::class, 'programme_day_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
