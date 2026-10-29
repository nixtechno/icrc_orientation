<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facilitator extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    public function sessions()
    {
        return $this->belongsToMany(ProgrammeSession::class, 'facilitator_session', 'facilitator_id', 'session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
