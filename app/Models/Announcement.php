<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Announcement extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    /** Remembered per request; reset it after changing the announcements table. */
    public static ?bool $activityLinks = null;

    /** Whether announcements can point at an activity yet, so the app keeps working before that migration has run. */
    public static function linksActivities(): bool
    {
        return self::$activityLinks ??= Schema::hasColumn('announcements', 'activity_id');
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    /** Published, unarchived announcements meant for this person's department and group, or for work assigned to them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('archived', false)->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('department')->orWhere('department', $user->department))
            ->where(fn ($q) => $q->whereNull('group_name')->orWhere('group_name', $user->group_name))
            ->when(self::linksActivities(), fn ($q) => $q->where(fn ($q) => $q->whereNull('activity_id')
                ->orWhereHas('activity', fn ($a) => $a->where('published', true)->whereHas('participants', fn ($p) => $p->where('users.id', $user->id)))));
    }

    /** Announcements published since the person last opened the Announcements page. */
    public static function unreadCount(User $user, int $programmeId): int
    {
        return static::where('programme_id', $programmeId)->visibleTo($user)
            ->when($user->announcements_seen_at, fn ($q, $seen) => $q->where('published_at', '>', $seen))
            ->count();
    }
}
