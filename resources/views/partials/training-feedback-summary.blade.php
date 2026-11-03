{{-- Averages and comments from one programme day's training feedback; who gave each response is not shown. --}}
@php
    $averages = collect(\App\Models\TrainingFeedback::STEPS)->map(fn ($step, $key) => round((float) $feedback->avg($key.'_rating'), 1));
    $comments = $feedback->pluck('comment')->filter()->values();
@endphp
<link rel="stylesheet" href="{{ asset('training-feedback.css') }}?v=20261103f">
<section class="card feedback-summary">
@if($days->isEmpty())<div class="empty">No feedback yet. It opens for staff at {{ $time }} on each programme day.</div>@else
<nav class="feedback-summary-days" aria-label="Programme days">@foreach($days as $d)<a href="/training-feedback?day={{ $d->id }}" @if($d->is($day)) aria-current="page" @endif>{{ $d->date->format('D j M') }}</a>@endforeach</nav>
<h2>{{ $day->date->format('l j F Y') }}</h2>
<p class="muted">{{ $feedback->count() }} {{ $feedback->count() === 1 ? 'response' : 'responses' }}@if($staff) from {{ $staff }} staff @endif · ratings out of 5</p>
<div class="feedback-summary-grid">@foreach(\App\Models\TrainingFeedback::STEPS as $key => $step)<div><span>{{ $step['title'] }}</span><strong>{{ number_format($averages[$key], 1) }}</strong><div class="feedback-summary-bar"><i style="width:{{ $averages[$key] * 20 }}%"></i></div></div>@endforeach</div>
@if($comments->isNotEmpty())<details @if($comments->count() <= 8) open @endif><summary>Suggestions ({{ $comments->count() }})</summary><ul class="feedback-comments">@foreach($comments as $comment)<li class="preline">{{ $comment }}</li>@endforeach</ul></details>@endif
@endif
</section>
