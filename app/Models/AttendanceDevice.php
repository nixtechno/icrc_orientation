<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['credential_id', 'credential_hash', 'public_key'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'last_verified_at' => 'datetime', 'revoked_at' => 'datetime', 'sign_count' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
