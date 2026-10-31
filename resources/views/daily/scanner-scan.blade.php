@extends('layout')
@section('content')
<script src="{{ asset('attendance-scanner.js') }}?v=20261030a" defer></script>
<div class="page-heading"><div><span class="eyebrow">BADGE SCANNER &middot; {{ now('Africa/Lagos')->format('j F Y') }}</span><h1>{{ $mode === 'sign-in' ? 'Sign-in station' : 'Sign-out station' }}</h1><p class="muted">Scan a staff badge. Attendance is recorded for today in Africa/Lagos time.</p></div><a href="/attendance/scanner">Back to scanner options</a></div>
<section class="card" style="max-width:800px"><h2>Ready to scan</h2><p>Keep the cursor in the field below. Set your scanner to press Enter after each QR code.</p>
<form method="post" action="/attendance/scanner/{{ $mode }}" data-attendance-scanner>@csrf
<label for="badge-scan">Staff badge QR code<input id="badge-scan" name="code" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="2048" required autofocus placeholder="Scan badge here" aria-describedby="scanner-result"></label>
<button type="submit">{{ $mode === 'sign-in' ? 'Record sign-in' : 'Record sign-out' }}</button>
</form>
<p id="scanner-result" role="status" aria-live="polite" aria-atomic="true" data-scanner-result>Waiting for a badge scan.</p>
<p class="muted">After each scan, the field clears and is ready for the next staff member. Duplicate scans do not create additional attendance records.</p></section>
@endsection
