@php
    $portal=auth()->check() && !request()->is('/', 'login', 'forgot-password', 'reset-password/*', 'invitation/*');
    $programme=\App\Models\Programme::where('code','ICRC-2026')->first();
    $logoUrl=$programme?->logoUrl()??(asset('brand/ICRC_Logo.png').'?v=20261027h');
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="theme-color" content="#075332"><meta name="description" content="@yield('description','ICRC SCODEX 2026 — Staff Capacity, Onboarding, Development &amp; Excellence Experience.')"><title>@yield('title','ICRC SCODEX 2026')</title><link rel="icon" type="image/png" href="{{ (asset('brand/ICRC_Logo.png').'?v=20261027h') }}"><link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon-180.png') }}?v=20261102a"><link rel="manifest" href="{{ asset('manifest.webmanifest') }}?v=20261102a"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="SCODEX 2026"><meta name="apple-mobile-web-app-status-bar-style" content="default"><script src="{{ asset('pwa.js') }}?v=20261102a" defer></script><link rel="stylesheet" href="{{ asset('app.css') }}?v=20261027h"><link rel="stylesheet" href="{{ asset('ceremony.css') }}?v=20261101e"><link rel="stylesheet" href="{{ asset('site-shell.css') }}?v=20261028b"><link rel="preload" href="{{ asset('fonts/manrope/Manrope-variable.ttf') }}" as="font" type="font/ttf" crossorigin><link rel="stylesheet" href="{{ asset('typography.css') }}?v=20261027a"><link rel="preload" as="image" href="{{ $logoUrl }}"><link rel="stylesheet" href="{{ asset('preloader.css') }}?v=20261027a"><script src="{{ asset('preloader.js') }}?v=20261027a"></script><script src="{{ asset('programme.js') }}?v=20261102a" defer></script></head>
<body id="top" class="{{ $portal?'portal-body':'guest-body' }} @yield('body-class')"><div id="icrc-preloader" class="icrc-preloader" role="status" aria-live="polite" aria-label="Loading ICRC SCODEX 2026"><div class="icrc-preloader-inner"><img class="icrc-preloader-logo" src="{{ $logoUrl }}" alt="ICRC — The Presidency" width="138" height="127" fetchpriority="high"><p class="icrc-preloader-title">ICRC SCODEX 2026</p><span class="icrc-preloader-status">Loading...</span></div></div><a class="skip" href="#main">Skip to content</a>
@if($portal)
<link rel="stylesheet" href="{{ asset('portal.css') }}?v=20261102a">
<script src="{{ asset('portal.js') }}?v=20261029c" defer></script>
<script src="{{ asset('search.js') }}?v=20261030a" defer></script>
@include('partials.portal-start')
@else
@include('partials.header')
@endif
@unless($portal)<div class="app-shell">@endunless
<link rel="stylesheet" href="{{ asset('form-ui.css') }}?v=20261030b">
<main id="main" class="{{ $portal?'workspace':'public-main' }}">@if($programme?->settings['demo_mode']??false)<div class="notice" role="note"><strong>DEMO ENVIRONMENT</strong> &middot; Synthetic test data in a separate database. Attendance records are simulated.</div>@endif {{-- Pages with their own narrow container can place the alerts and back link inside it. --}}@unless(View::hasSection('inline-alerts'))@include('partials.alerts') @include('partials.back-link')@endunless @yield('content')</main></div>
@if($portal)</div></div>@else @include('partials.footer') @endif
@if($portal&&$programme&&($feedbackOpens=\App\Models\TrainingFeedback::opensFor(auth()->user())))@include('partials.training-feedback-dialog',['opens'=>$feedbackOpens])@endif
</body></html>
