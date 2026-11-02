<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes a person and their own records. A backup of everything removed is saved first. People who
 * set up records other people depend on (staff groups, badges they generated, learning materials) can only be
 * deactivated. Actions they recorded for others, such as scanning someone's badge, stay with the link cleared.
 */
class PersonDeletion
{
    /** The person's own records, removed with them. */
    private const OWN = ['activity_assignments', 'assessment_group_user', 'attendance_challenges', 'attendance_device_requests', 'attendance_devices',
        'daily_attendance', 'enrolments', 'invitations', 'legacy_session_attendance', 'participant_imports', 'session_answers', 'session_questions', 'submissions', 'badges'];

    /** Actions they took on other records, kept with the link cleared. */
    private const CLEAR = ['attendance_audit_logs' => ['user_id', 'actor_id'], 'attendance_device_requests' => ['approved_by'], 'attendance_devices' => ['active_user_id'],
        'audit_logs' => ['user_id'], 'daily_attendance' => ['recorded_by', 'signin_scanned_by', 'signout_scanned_by'], 'facilitators' => ['user_id'], 'timetable_proposals' => ['reviewed_by']];

    /** Why the person cannot be deleted, or null when they can. */
    public function blocker(User $user): ?string
    {
        $made = array_filter([
            'staff groups' => DB::table('assessment_groups')->where('created_by', $user->id)->exists(),
            "other people's badges" => DB::table('badges')->where('generated_by', $user->id)->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))->exists(),
            'learning materials' => DB::table('resources')->where('uploaded_by', $user->id)->exists(),
        ]);

        return $made ? $user->name.' set up '.implode(', ', array_keys($made)).' that others still use, so this account can only be deactivated.' : null;
    }

    /** What would be removed, for the confirmation. */
    public function counts(User $user): array
    {
        return array_filter([
            'attendance records' => DB::table('daily_attendance')->where('user_id', $user->id)->count(),
            'submissions' => DB::table('submissions')->where('user_id', $user->id)->count(),
            'badges' => DB::table('badges')->where('user_id', $user->id)->count(),
            'assignments' => DB::table('activity_assignments')->where('user_id', $user->id)->count(),
            'Q&A posts' => DB::table('session_questions')->where('user_id', $user->id)->count() + DB::table('session_answers')->where('user_id', $user->id)->count(),
        ]);
    }

    /** @return string the backup file, relative to the private storage disk */
    public function delete(User $user): string
    {
        abort_if($reason = $this->blocker($user), 422, $reason);

        return DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $backup = $this->backup($user);
            $files = DB::table('submissions')->where('user_id', $user->id)->whereNotNull('path')->pluck('path');
            foreach (self::CLEAR as $table => $columns) {
                foreach ($columns as $column) DB::table($table)->where($column, $user->id)->update([$column => null]);
            }
            foreach (self::OWN as $table) DB::table($table)->where('user_id', $user->id)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($files->all()));
            Access::audit('user.delete', (string) $user->id, ['name' => $user->name, 'role' => $user->role], ['email' => $user->email, 'backup' => $backup]);

            return $backup;
        });
    }

    private function backup(User $user): string
    {
        $rows = ['user' => DB::table('users')->where('id', $user->id)->first()];
        foreach (array_unique([...self::OWN, ...array_keys(self::CLEAR)]) as $table) {
            $columns = array_unique([...(in_array($table, self::OWN, true) ? ['user_id'] : []), ...(self::CLEAR[$table] ?? [])]);
            $found = DB::table($table)->where(fn ($q) => collect($columns)->each(fn ($c) => $q->orWhere($c, $user->id)))->get();
            if ($found->isNotEmpty()) $rows[$table] = $found;
        }
        $path = 'backups/deleted-people/'.now()->format('Y-m-d-His').'-'.\Illuminate\Support\Str::slug($user->email).'.json';
        Storage::disk('local')->put($path, json_encode(['deleted_at' => now()->toIso8601String(), 'by' => auth()->user()?->email, ...$rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
