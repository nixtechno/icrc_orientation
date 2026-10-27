<?php

namespace App\Services;

use App\Models\Programme;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Access
{
    public static function programme(): Programme
    {
        return Programme::where('code', 'ICRC-2026')->firstOrFail();
    }

    public static function enrolled(?User $user = null): void
    {
        $u = $user ?? auth()->user();
        abort_unless($u && $u->active && ($u->role === 'admin' || $u->programmes()->where('programmes.id', self::programme()->id)->exists()), 403);
    }

    public static function manage(string $permission): void
    {
        self::enrolled();
        abort_unless(auth()->user()->canManage($permission), 403);
    }

    public static function audit(string $action, string $subject, $before = null, $after = null): void
    {
        DB::table('audit_logs')->insert(['user_id' => auth()->id(), 'action' => $action, 'subject' => $subject, 'before' => $before === null ? null : json_encode(self::redact($before)), 'after' => $after === null ? null : json_encode(self::redact($after)), 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function redact($v)
    {
        if (is_object($v)) {
            $v = (array) $v;
        }if (! is_array($v)) {
            return $v;
        }foreach ($v as $k => &$x) {
            if (preg_match('/password|token|secret|profile|emergency|accommodation/i', (string) $k)) {
                $x = '[redacted]';
            } else {
                $x = self::redact($x);
            }
        }

return $v;
    }

    public static function staff()
    {
        return User::where('role', 'staff')->whereHas('programmes', fn ($q) => $q->where('programmes.id', self::programme()->id));
    }
}
