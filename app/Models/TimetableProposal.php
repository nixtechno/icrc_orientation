<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimetableProposal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source' => 'array', 'proposed' => 'array', 'baseline' => 'array', 'warnings' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function session()
    {
        return $this->belongsTo(ProgrammeSession::class, 'session_id');
    }

    public function day()
    {
        return $this->belongsTo(ProgrammeDay::class, 'programme_day_id');
    }
}
