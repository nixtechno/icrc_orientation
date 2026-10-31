@extends('layout')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BADGE VERIFICATION</span><h1>{{ $badge->snapshot['name'] }}</h1><p class="muted">{{ ucfirst($badge->category) }} &middot; Version {{ $badge->version }}</p></div><a class="button secondary" href="/badges/{{ $badge->getRouteKey() }}/edit">Open badge editor</a></div>
<section class="card"><h2>Verify identity &amp; confirm collection</h2><p>Check the holder's identity against this profile before recording collection.</p><dl><dt>Title</dt><dd>{{ $badge->snapshot['designation']??'Not specified' }}</dd><dt>Organization / department</dt><dd>{{ $badge->snapshot['organisation']??$badge->snapshot['department']??'Not specified' }}</dd></dl>
@include('badges.collection-form')
</section>
@endsection
