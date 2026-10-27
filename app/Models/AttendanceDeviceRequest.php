<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDeviceRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'enrolled_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
