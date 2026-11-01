@extends('layout')
@section('title',$heading.' · ICRC SCODEX 2026')
@section('content')<section class="section legal-page">@include('partials.back-link')<span class="eyebrow">ICRC SCODEX 2026</span><h1>{{ $heading }}</h1><div class="legal-copy">@include('legal.'.$doc)</div></section>@endsection
