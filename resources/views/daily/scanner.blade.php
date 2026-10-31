@extends('layout')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BADGE ATTENDANCE</span><h1>Scan with scanner</h1><p class="muted">Choose the attendance station, then scan staff badges with your QR code scanner.</p></div><a href="/attendance">Back to attendance</a></div>
<section class="card"><h2>Choose a station</h2><p>Each station opens in its own tab and records attendance for today using the configured sign-in or sign-out window.</p><div class="buttons"><a class="button" href="/attendance/scanner/sign-in" target="_blank" rel="noopener" aria-label="Sign in station (opens in a new tab)">Sign in <span aria-hidden="true">↗</span></a><a class="button secondary" href="/attendance/scanner/sign-out" target="_blank" rel="noopener" aria-label="Sign out station (opens in a new tab)">Sign out <span aria-hidden="true">↗</span></a></div></section>
@if (\App\Services\AttendanceSync::venue())
<section class="card" data-attendance-sync><h2>Venue sync</h2><p>Scans are saved on this venue server first. Every two minutes it uploads them to the live site and downloads people, badges and phone sign-ins.</p><p role="status" aria-live="polite" data-sync-text>Checking sync…</p><form method="post" action="/attendance/sync" data-sync-now>@csrf<button class="button secondary" type="submit">Sync now</button></form></section>
@else
<section class="card" data-attendance-sync><h2>Venue sync</h2><p role="status" data-sync-text>Checking venue uploads…</p></section>
@endif
<script src="{{ asset('attendance-sync.js') }}?v=20261101a" defer></script>
@endsection
