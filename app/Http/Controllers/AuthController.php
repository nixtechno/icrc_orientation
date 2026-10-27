<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth', ['mode' => 'login']);
    }

    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$v, 'active' => true])) {
            return back()->withErrors(['email' => 'The credentials are invalid or the account is inactive.'])->onlyInput('email');
        }$r->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('success', 'If an account exists, a password reset link has been sent.');
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $password) {
            $u->password = $password;
            $u->remember_token = Str::random(60);
            $u->save();
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }

    public function invitation(string $token)
    {
        $i = $this->validInvitation($token);

        return view('auth', ['mode' => 'invite', 'token' => $token, 'inviteUser' => User::findOrFail($i->user_id)]);
    }

    private function validInvitation(string $token)
    {
        return DB::table('invitations')->where('token_hash', hash('sha256', $token))->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
    }

    public function activate(Request $r, string $token)
    {
        $v = $r->validate(['name' => 'required|string|max:255', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        DB::transaction(function () use ($v, $token) {
            $i = DB::table('invitations')->where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_if($i->used_at || $i->revoked_at || now()->gte($i->expires_at), 410, 'Invitation expired or already used.');
            $u = User::findOrFail($i->user_id);
            $u->forceFill(['name' => $v['name'], 'password' => $v['password'], 'active' => true, 'email_verified_at' => now()])->save();
            DB::table('invitations')->where('id', $i->id)->update(['used_at' => now()]);
        });

        return redirect('/login')->with('success', 'Account activated. Sign in with your new password.');
    }

    public function profile()
    {
        return view('profile');
    }

    public function updateProfile(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:255', 'phone' => 'nullable|string|max:40', 'password' => ['nullable', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()], 'current_password' => 'required_with:password|current_password']);
        $u = $r->user();
        $u->name = $v['name'];
        $u->phone = $v['phone'];
        if (! empty($v['password'])) {
            $u->password = $v['password'];
            $u->remember_token = Str::random(60);
        }$u->save();

        return back()->with('success','Profile updated.');
    }
}
