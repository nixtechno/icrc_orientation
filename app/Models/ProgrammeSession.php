<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgrammeSession extends Model
{
    protected $table = 'programme_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source' => 'array', 'warnings' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'reviewed' => 'boolean', 'attendance_required' => 'boolean'];
    }

    public function day()
    {
        return $this->belongsTo(ProgrammeDay::class, 'programme_day_id');
    }
}
