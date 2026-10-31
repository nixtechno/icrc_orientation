<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    /** Published, unarchived announcements meant for this person's department and group. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('archived', false)->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('department')->orWhere('department', $user->department))
            ->where(fn ($q) => $q->whereNull('group_name')->orWhere('group_name', $user->group_name));
    }

    /** Announcements published since the person last opened the Announcements page. */
    public static function unreadCount(User $user, int $programmeId): int
    {
        return static::where('programme_id', $programmeId)->visibleTo($user)
            ->when($user->announcements_seen_at, fn ($q, $seen) => $q->where('published_at', '>', $seen))
            ->count();
    }
}
