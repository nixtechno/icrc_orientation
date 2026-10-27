<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionAnswer extends Model
{
    protected $guarded = [];

    public function question()
    {
        return $this->belongsTo(SessionQuestion::class, 'session_question_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
