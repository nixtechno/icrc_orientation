@extends('layout')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BADGE ATTENDANCE</span><h1>Scan with scanner</h1><p class="muted">Choose the attendance station, then scan staff badges with your QR code scanner.</p></div><a href="/attendance">Back to attendance</a></div>
<section class="card"><h2>Choose a station</h2><p>Each station opens in its own tab and records attendance for today using the configured sign-in or sign-out window.</p><div class="buttons"><a class="button" href="/attendance/scanner/sign-in" target="_blank" rel="noopener" aria-label="Sign in station (opens in a new tab)">Sign in <span aria-hidden="true">↗</span></a><a class="button secondary" href="/attendance/scanner/sign-out" target="_blank" rel="noopener" aria-label="Sign out station (opens in a new tab)">Sign out <span aria-hidden="true">↗</span></a></div></section>
@endsection
