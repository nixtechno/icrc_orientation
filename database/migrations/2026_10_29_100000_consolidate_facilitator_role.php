<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('users')->whereIn('role', ['supervisor', 'facilitator'])->lockForUpdate()->get() as $user) {
                $permissions = json_decode($user->permissions ?? '[]', true) ?? [];
                $updated = array_values(array_unique([...$permissions, 'assessments', 'resources', 'announcements', 'qa_answer']));
                DB::table('users')->where('id', $user->id)->update(['role' => 'facilitator', 'permissions' => json_encode($updated), 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['user_id' => null, 'action' => 'role.facilitator_migration', 'subject' => (string) $user->id, 'before' => json_encode(['role' => $user->role, 'permissions' => $permissions]), 'after' => json_encode(['role' => 'facilitator', 'permissions' => $updated]), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('participant_imports')->where('role', 'supervisor')->update(['role' => 'facilitator']);
            foreach (DB::table('badges')->where('category', 'supervisor')->get() as $badge) {
                $snapshot = json_decode($badge->snapshot, true);
                $snapshot['category'] = 'facilitator';
                DB::table('badges')->where('id', $badge->id)->update(['category' => 'facilitator', 'snapshot' => json_encode($snapshot), 'printed_at' => null, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // The supported role set remains Admin/Facilitator/Staff. Historical roles are retained in audit_logs.
    }
};
