<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgrammeDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'attendance_required' => 'boolean', 'cancelled' => 'boolean'];
    }

    public function week()
    {
        return $this->belongsTo(ProgrammeWeek::class, 'programme_week_id');
    }

    public function sessions()
    {
        return $this->hasMany(ProgrammeSession::class);
    }
}
