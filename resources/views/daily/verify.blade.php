@extends('layout')
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<div class="page-heading"><div><span class="eyebrow">SECURE DAILY ATTENDANCE</span><h1>{{ $mode==='device'?'Register your attendance device':($mode==='sign-in'?'Sign in to today’s programme':'Sign out of today’s programme') }}</h1><p class="muted">{{ now('Africa/Lagos')->format('l j F Y') }} · Africa/Lagos</p></div><a href="/attendance">My attendance</a></div>
<section class="card" style="max-width:760px;margin:auto" data-attendance-verification data-purpose="{{ $mode==='device'?'register':($mode==='sign-in'?'in':'out') }}">
@if($mode==='device')
<h2>One account. One authorized credential.</h2><p>Register on the device you will bring each day. Your device must offer a non-synchronized platform credential with fingerprint, face recognition or device PIN verification. Biometric data and PINs stay on your device.</p>
@if($device)<p class="badge">Registered: {{ $device->label }}</p><p>Request supervisor approval from My attendance if this device needs replacing.</p>@else
<label>Device label<input id="attendance-device-label" maxlength="100" required placeholder="e.g. My work phone"></label><label>Confirm your account password<input id="attendance-password" type="password" autocomplete="current-password" required></label><button type="button" data-verify>Register this device</button>
@endif
@else
<h2>Verify your device and current location</h2><p>Use your registered device, enable precise location and remain at the approved venue. Each sign-in and sign-out needs a fresh device verification.</p>
@if(!$device)<p>Register an authorized device before recording attendance.</p><a class="button" href="/attendance/device">Register my device</a>@else<p class="muted">Registered device: {{ $device->label }}</p><button type="button" data-verify>Verify and {{ $mode==='sign-in'?'sign in':'sign out' }}</button>@endif
<p class="meta">Browser location is an anti-abuse check; it cannot guarantee physical presence. Your location is encrypted and retained according to the programme’s attendance policy.</p>
@endif
<p role="status" aria-live="polite" data-verification-status></p><p class="muted">Use the configured HTTPS application address. Devices that only offer synchronized passkeys cannot be enrolled under this attendance policy.</p>
</section>
<script src="{{ asset('attendance-security.js') }}" defer></script>
@endsection
