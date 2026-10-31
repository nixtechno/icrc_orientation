<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="theme-color" content="#075332"><meta name="robots" content="noindex"><title>Badge printing · ICRC SCODEX 2026</title><link rel="icon" type="image/png" href="{{ asset('brand/ICRC_Logo.png') }}?v=20261027h"><link rel="stylesheet" href="{{ asset('attendance-station.css') }}?v=20261031d"><script src="{{ asset('badge-print-station.js') }}?v=20261031a" defer></script></head>
<body class="station station-print">
<main class="station-panel">
<header class="station-brand"><img src="{{ $logoUrl }}" alt="Infrastructure Concession Regulatory Commission — The Presidency" width="138" height="127"><span>ICRC SCODEX 2026</span></header>
<h1>Print your badge</h1>
<p class="station-lead">Scan the QR code from your invitation email</p>
<form method="post" action="/badges/print-station" data-badge-print-station>@csrf
<label class="station-label" for="print-scan">Badge printing QR code</label>
<input id="print-scan" name="code" type="password" inputmode="numeric" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="64" required autofocus placeholder="Scan QR code" aria-describedby="print-result" data-lpignore="true" data-1p-ignore data-form-type="other">
</form>
<p id="print-result" class="muted" role="status" aria-live="polite" aria-atomic="true" data-print-result>Waiting for a QR code scan.</p>
</main>
<iframe class="station-print-frame" title="Badge for printing" tabindex="-1" aria-hidden="true" data-print-frame></iframe>
</body></html>
