<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgrammeWeek extends Model
{
    protected $guarded = [];

    public function programme()
    {
        return $this->belongsTo(Programme::class);
    }

    public function days()
    {
        return $this->hasMany(ProgrammeDay::class);
    }
}
