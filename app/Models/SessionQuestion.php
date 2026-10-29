<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionQuestion extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    public function session()
    {
        return $this->belongsTo(ProgrammeSession::class, 'session_id');
    }

    public function answers()
    {
        return $this->hasMany(SessionAnswer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
