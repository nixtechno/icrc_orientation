<?php

namespace App\Services;

use App\Models\{Badge, DailyAttendance, User};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds profiles that look like the same person (same first name and surname) and merges a duplicate into the
 * profile being kept: everything the duplicate owns moves across, rows both profiles have are combined, then the
 * duplicate is removed. The kept profile's sign-in details, role and email always stay as they are.
 */
class PersonMerge
{
    /** Columns that point at a person and can simply be moved to the kept profile. */
    private const MOVE = [
        'attendance_audit_logs' => ['user_id', 'actor_id'], 'attendance_device_requests' => ['user_id', 'approved_by'],
        'audit_logs' => ['user_id'], 'badges' => ['generated_by'], 'daily_attendance' => ['recorded_by', 'signin_scanned_by', 'signout_scanned_by'],
        'facilitators' => ['user_id'], 'invitations' => ['user_id'], 'participant_imports' => ['user_id'], 'resources' => ['uploaded_by'],
        'session_answers' => ['user_id'], 'session_questions' => ['user_id'], 'assessment_groups' => ['created_by'],
        'timetable_proposals' => ['reviewed_by'], 'attendance_devices' => ['user_id'],
    ];

    /** Details copied from the duplicate when the kept profile has none. */
    private const FILL = ['staff_number', 'department', 'designation', 'group_name', 'phone', 'photo_path', 'responsibilities'];

    /** First and last word of the name, ignoring case, spacing and punctuation. */
    public static function key(User $user): string
    {
        $profile = $user->profile ?? [];
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]+/u', ' ', mb_strtolower(trim(($profile['first_name'] ?? '').' '.($profile['surname'] ?? '')) ?: $user->name))));

        return count($words) > 1 ? $words[0].' '.end($words) : ($words[0] ?? '');
    }

    /** @return Collection<string, Collection<int, User>> groups of two or more profiles that share a name, not yet marked as different people */
    public function candidates(): Collection
    {
        $dismissed = collect(Access::programme()->settings['distinct_people'] ?? []);

        return User::orderBy('id')->get()->groupBy(fn (User $u) => self::key($u))
            ->filter(fn ($group, $key) => $key !== '' && $group->count() > 1 && ! $dismissed->contains($this->signature($group)));
    }

    /** Identifies a group so it stays dismissed until its members change. */
    public function signature(Collection $group): string
    {
        return $group->pluck('id')->sort()->implode('-');
    }

    /** $wearing is the profile whose printed badge the person wears, when both profiles have one printed. */
    public function merge(User $keep, User $drop, ?User $wearing = null): User
    {
        abort_if($keep->is($drop), 422, 'Choose two different profiles.');

        return DB::transaction(function () use ($keep, $drop, $wearing) {
            [$keep, $drop] = [User::whereKey($keep->id)->lockForUpdate()->firstOrFail(), User::whereKey($drop->id)->lockForUpdate()->firstOrFail()];
            $before = ['kept' => $keep->toArray(), 'removed' => $drop->toArray()];

            $this->badges($keep, $drop, $wearing);
            $this->attendance($keep, $drop);
            $this->submissions($keep, $drop);
            $this->unique('enrolments', 'programme_id', $keep, $drop);
            $this->unique('activity_assignments', 'activity_id', $keep, $drop);
            $this->unique('assessment_group_user', 'assessment_group_id', $keep, $drop);
            $this->unique('legacy_session_attendance', 'session_id', $keep, $drop);
            $this->devices($keep, $drop);
            foreach (self::MOVE as $table => $columns) {
                foreach ($columns as $column) DB::table($table)->where($column, $drop->id)->update([$column => $keep->id]);
            }
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $drop->id)->update(['notifiable_id' => $keep->id]);
            DB::table('attendance_challenges')->where('user_id', $drop->id)->delete();
            DB::table('sessions')->where('user_id', $drop->id)->delete();
            DB::table('password_reset_tokens')->where('email', $drop->email)->delete();

            // Unique details move across only after the duplicate lets go of them.
            $fill = collect(self::FILL)->filter(fn ($field) => blank($keep->$field) && filled($drop->$field))->mapWithKeys(fn ($field) => [$field => $drop->$field])->all();
            $profile = array_filter($keep->profile ?? [], fn ($value) => filled($value)) + array_filter($drop->profile ?? [], fn ($value) => filled($value));
            $email = $drop->email;
            $drop->forceFill(['staff_number' => null])->saveQuietly();
            $drop->delete();
            $keep->forceFill([...$fill, 'profile' => $profile ?: $keep->profile])->save();
            Access::audit('user.merge', (string) $keep->id, $before, ['from_email' => $email, 'into_email' => $keep->email, 'from_id' => $drop->id, 'into_id' => $keep->id, 'badge_from_email' => $wearing?->is($drop) ? $email : null]);

            return $keep;
        });
    }

    /**
     * Keeps one badge: the one the person wears when that is known, otherwise the kept profile's unless only the
     * duplicate's was printed. A printed badge brings its QR ID across so the badge being worn still scans.
     */
    private function badges(User $keep, User $drop, ?User $wearing): void
    {
        foreach (Badge::where('user_id', $drop->id)->get() as $theirs) {
            $mine = Badge::where('programme_id', $theirs->programme_id)->where('user_id', $keep->id)->first();
            $keepMine = $wearing ? ! $wearing->is($drop) : ($mine?->printed_at || ! $theirs->printed_at);
            if ($mine && $keepMine) {
                foreach (['printed_at', 'collected_at', 'materials_collected_at'] as $field) $mine->$field ??= $theirs->$field;
                $mine->save();
                $theirs->delete();
                continue;
            }
            $mine?->delete();
            $theirs->forceFill(['user_id' => $keep->id, 'subject_key' => 'u:'.$keep->id])->save();
            if ($theirs->printed_at && $drop->qr_code) {
                $code = $drop->qr_code;
                $drop->forceFill(['qr_code' => null])->saveQuietly();
                $keep->forceFill(['qr_code' => $code])->saveQuietly();
            }
        }
    }

    /** One record per day: the earliest sign-in and the latest sign-out of the two. */
    private function attendance(User $keep, User $drop): void
    {
        foreach (DailyAttendance::where('user_id', $drop->id)->get() as $theirs) {
            $mine = DailyAttendance::where('programme_id', $theirs->programme_id)->where('programme_day_id', $theirs->programme_day_id)->where('user_id', $keep->id)->first();
            if (! $mine) {
                $theirs->forceFill(['user_id' => $keep->id, 'staff_name' => $keep->name])->save();
                continue;
            }
            $sides = ['in' => ['checked_in_at', 'signin', fn ($a, $b) => $a->lt($b)], 'out' => ['checked_out_at', 'signout', fn ($a, $b) => $a->gt($b)]];
            foreach ($sides as [$column, $prefix, $better]) {
                if ($theirs->$column && (! $mine->$column || $better($theirs->$column, $mine->$column))) {
                    foreach (array_keys($mine->getAttributes()) as $field) {
                        if ($field === $column || str_starts_with($field, $prefix.'_')) $mine->$field = $theirs->$field;
                    }
                    if ($column === 'checked_in_at') [$mine->status, $mine->punctuality_status] = [$theirs->status, $theirs->punctuality_status];
                }
            }
            $mine->duration_seconds = $mine->checked_in_at && $mine->checked_out_at ? max(0, (int) $mine->checked_in_at->diffInSeconds($mine->checked_out_at)) : null;
            $mine->save();
            $theirs->delete();
        }
    }

    /** Attempts on the same activity keep their order: the duplicate's are numbered after the kept profile's. */
    private function submissions(User $keep, User $drop): void
    {
        foreach (DB::table('submissions')->where('user_id', $drop->id)->orderBy('attempt')->get() as $row) {
            $last = (int) DB::table('submissions')->where('activity_id', $row->activity_id)->where('user_id', $keep->id)->max('attempt');
            DB::table('submissions')->where('id', $row->id)->update(['user_id' => $keep->id, 'attempt' => $last + 1]);
        }
    }

    /** Moves membership rows the kept profile lacks and drops the ones it already has. */
    private function unique(string $table, string $column, User $keep, User $drop): void
    {
        $has = DB::table($table)->where('user_id', $keep->id)->pluck($column);
        DB::table($table)->where('user_id', $drop->id)->whereIn($column, $has)->delete();
        DB::table($table)->where('user_id', $drop->id)->update(['user_id' => $keep->id]);
    }

    private function devices(User $keep, User $drop): void
    {
        $active = DB::table('attendance_devices')->where('active_user_id', $keep->id)->exists();
        DB::table('attendance_devices')->where('active_user_id', $drop->id)->update(['active_user_id' => $active ? null : $keep->id]);
    }
}
