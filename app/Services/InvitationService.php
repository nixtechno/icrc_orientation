<?php

namespace App\Services;

use App\Mail\ProgrammeInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationService
{
    public function send(User $u): void
    {
        abort_if($u->active, 422, 'Account is active. Use password reset.');
        $token = Str::random(64);
        $expiresAt = now()->addDays(3);
        DB::transaction(function () use ($u, $token, $expiresAt) {
            User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            DB::table('invitations')->where('user_id', $u->id)->whereNull('used_at')->update(['revoked_at' => now()]);
            DB::table('invitations')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit('invitation.send', (string) $u->id);
        });
        Mail::to($u->email)->send(new ProgrammeInvitation($u, Access::programme(), url('/invitation/'.$token), $expiresAt));
    }
}
