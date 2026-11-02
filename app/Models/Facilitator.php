<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facilitator extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    protected static function booted(): void
    {
        // Badges copy these details when generated, so keep them current wherever the profile is edited.
        static::updated(function (Facilitator $facilitator): void {
            if ($facilitator->wasChanged(['name', 'job_title', 'department', 'organisation', 'photo_path'])) {
                app(\App\Services\BadgeService::class)->refresh($facilitator);
            }
        });
    }

    public function sessions()
    {
        return $this->belongsToMany(ProgrammeSession::class, 'facilitator_session', 'facilitator_id', 'session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
