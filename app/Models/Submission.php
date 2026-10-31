<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];
    protected $hidden = ['launch_token_hash'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'released_at' => 'datetime', 'fullscreen_started_at' => 'datetime', 'draft_version' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
