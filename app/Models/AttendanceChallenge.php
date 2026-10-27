<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['challenge', 'session_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
