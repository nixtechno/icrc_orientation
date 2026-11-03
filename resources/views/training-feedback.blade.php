@extends('layout')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PROGRAMME INSIGHT</div><h1>Training feedback</h1><p class="muted">Staff are asked about each programme day's training at {{ $time }}. Change the time in Settings &rarr; Data tools.</p></div></div>
@include('partials.training-feedback-summary')
@endsection
