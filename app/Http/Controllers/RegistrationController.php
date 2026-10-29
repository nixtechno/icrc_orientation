<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegistrationController extends Controller
{
    private function invitation(string $token)
    {
        return DB::table('invitations')->where('token_hash', hash('sha256', $token))->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
    }

    public function activateForm(string $token)
    {
        $i = $this->invitation($token);

        return view('people.activate', ['person' => User::findOrFail($i->user_id), 'token' => $token]);
    }

    public function activate(Request $r, string $token, ProfileService $profiles)
    {
        $i = DB::table('invitations')->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($i->used_at || $i->revoked_at || now()->gte($i->expires_at), 410, 'Invitation has expired, been revoked or already been used.');
        $u = User::findOrFail($i->user_id);
        $fixed = ['email' => $u->email];
        foreach ($u->role === 'facilitator' ? [] : ['staff_number', 'department', 'designation', 'group_name'] as $field) {
            if ($u->$field) {
                $fixed[$field] = $u->$field;
            }
        }$r->merge($fixed);
        $rules = $profiles->rules($u, true);
        if ($u->photo_path) {
            $rules['photo'] = 'nullable|image|mimes:jpg,jpeg,png|max:2048';
        }$v = $r->validate([...$rules, 'password' => ['required', 'confirmed', Password::min(8)], 'privacy_acknowledged' => 'accepted']);
        app(\App\Services\ActivationCaptcha::class)->verify($r);
        DB::transaction(function () use ($u, $v, $i, $profiles, $r) {
            $locked = DB::table('invitations')->where('id', $i->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->used_at || $locked->revoked_at || now()->gte($locked->expires_at), 410);
            $profiles->assign($u, $v);
            $profiles->photo($r, $u);
            $u->forceFill(['password' => $v['password'], 'active' => true, 'email_verified_at' => now(), 'privacy_acknowledged_at' => now()])->save();
            $profiles->syncFacilitator($u);
            DB::table('invitations')->where('id', $i->id)->update(['used_at' => now(), 'updated_at' => now()]);
            Access::audit('account.activate', (string) $u->id, null, ['role' => $u->role]);
        });

        return redirect('/login')->with('success', 'Account activated. Sign in with your chosen password.');
    }

    public function profile()
    {
        return view('people.profile', ['person' => auth()->user()]);
    }

    public function update(Request $r, ProfileService $profiles)
    {
        $u = $r->user();
        $r->merge($u->role === 'facilitator' ? ['email' => $u->email] : ['email' => $u->email, 'staff_number' => $u->staff_number, 'department' => $u->department, 'designation' => $u->designation, 'group_name' => $u->group_name]);
        $rules = $profiles->rules($u);
        $v = $r->validate([...$rules, 'first_name' => 'required|string|max:100', 'surname' => 'required|string|max:100', 'phone' => 'required|string|max:40', 'password' => ['nullable', 'confirmed', Password::min(8)], 'current_password' => 'required_with:password|current_password']);
        $profiles->assign($u, $v, false);
        $profiles->photo($r, $u);
        if (! empty($v['password'])) {
            $u->password = $v['password'];
            $u->remember_token = Str::random(60);
        }$u->save();
        $profiles->syncFacilitator($u);
        Access::audit('profile.update',(string) $u->id);

        return back()->with('success','Personal profile updated.');
    }
}
