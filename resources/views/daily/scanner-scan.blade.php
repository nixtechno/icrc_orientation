@php($in = $mode === 'sign-in')
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="theme-color" content="#075332"><meta name="robots" content="noindex"><title>{{ $in ? 'Sign in' : 'Sign out' }} · ICRC SCODEX 2026</title><link rel="icon" type="image/png" href="{{ asset('brand/ICRC_Logo.png') }}?v=20261027h"><link rel="stylesheet" href="{{ asset('attendance-station.css') }}?v=20261031d"><script src="{{ asset('attendance-scanner.js') }}?v=20261031b" defer></script></head>
<body class="station station-{{ $mode }}">
<main class="station-panel">
<header class="station-brand"><img src="{{ $logoUrl }}" alt="Infrastructure Concession Regulatory Commission — The Presidency" width="138" height="127"><span>ICRC SCODEX 2026</span></header>
<h1>{{ $in ? 'Sign in' : 'Sign out' }}</h1>
<p class="station-lead">{{ $in ? 'Scan to sign in' : 'Scan to sign out' }}</p>
<form method="post" action="/attendance/scanner/{{ $mode }}" data-attendance-scanner>@csrf
<label class="station-label" for="badge-scan">Badge QR code</label>
<input id="badge-scan" name="code" type="password" inputmode="numeric" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="2048" required autofocus placeholder="Scan badge" aria-describedby="scanner-result" data-lpignore="true" data-1p-ignore data-form-type="other">
</form>
<p id="scanner-result" class="muted" role="status" aria-live="polite" aria-atomic="true" data-scanner-result>Waiting for a badge scan.</p>
<p class="station-date">{{ now('Africa/Lagos')->format('l j F Y') }}</p>
</main>
</body></html>
