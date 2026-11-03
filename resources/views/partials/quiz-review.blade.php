{{-- A submitted assessment, one question at a time with a numbered map. Staff see only their own answers, never the correct answers or explanations, so a reset attempt stays fair. --}}
@php
    $review = app(\App\Services\QuizAttemptService::class)->review($activity, $s);
    $released = (bool) $s->released_at;
    $total = $activity->questions->sum('points');
    $labels = ['unanswered' => 'Not answered', 'written' => 'Written answer', 'answered' => 'Answered'];
    $shown = fn ($status) => in_array($status, ['unanswered', 'written'], true) ? $status : 'answered';
    $prefix = 'q'.$s->getRouteKey().'-';
@endphp
<div class="quiz-review" data-quiz-review>
@unless($released && $s->score !== null)
<p class="notice quiz-review-pending" role="status"><strong>Your answers are with a facilitator for marking.</strong> Your score appears here once your result is released.</p>
@endunless
<div class="quiz-review-layout">
<div class="quiz-review-main">
@foreach($review as $i => $item)
@php($q = $item['question'])
@php($status = $shown($item['status']))
<article class="quiz-review-question" id="{{ $prefix.($i + 1) }}" data-review-question tabindex="-1" aria-labelledby="{{ $prefix.($i + 1) }}-title">
<header class="quiz-review-question-head"><span class="quiz-review-number">Question {{ $i + 1 }} of {{ count($review) }}</span><span class="quiz-review-status is-{{ $status }}">{{ $labels[$status] }}</span></header>
<h3 class="quiz-review-prompt preline" id="{{ $prefix.($i + 1) }}-title">{{ $q->prompt }}</h3>
<p class="quiz-review-marks">{{ $q->points }} {{ $q->points == 1 ? 'mark' : 'marks' }} @if($q->type === 'multiple_answers') &middot; Select all that apply @endif</p>
@if($q->options)
<ol class="quiz-review-options">
@foreach($q->options as $o => $option)
@php($mine = in_array($option, $item['selected'], true))
<li class="quiz-review-option {{ $mine ? 'is-selected' : '' }}"><span class="quiz-review-letter" aria-hidden="true">{{ chr(65 + $o) }}</span><span class="quiz-review-text">{{ $option }}</span>
@if($mine)<span class="quiz-review-tag">Your answer</span>@endif
<span class="quiz-review-icon" aria-hidden="true"></span></li>
@endforeach
</ol>
@if($item['status'] === 'unanswered')<p class="quiz-review-note">You did not answer this question.</p>@endif
@else
<div class="quiz-review-response"><span>Your response</span>@if($item['response'] !== '')<p class="preline">{{ $item['response'] }}</p>@else<p class="muted">No response.</p>@endif</div>
<p class="quiz-review-note">Written answers are marked by a facilitator as part of your overall score.</p>
@endif
</article>
@endforeach
<div class="quiz-review-pager" data-review-pager hidden><button type="button" class="secondary small" data-review-prev>&larr; Previous</button><span data-review-position aria-live="polite"></span><button type="button" class="small" data-review-next>Next &rarr;</button></div>
</div>
<aside class="quiz-review-map">
@if($released && $s->score !== null)<div class="quiz-review-score"><span>Your score</span><strong>{{ +$s->score }}@if($total)<small> / {{ +$total }}</small>@endif</strong>@if($total)<em>{{ round($s->score / $total * 100) }}%</em>@endif</div>@endif
<nav aria-label="Questions">
<p class="quiz-review-map-title">Questions</p>
<div class="quiz-review-tiles">@foreach($review as $i => $item)@php($status = $shown($item['status']))<a class="quiz-review-tile is-{{ $status }}" href="#{{ $prefix.($i + 1) }}" data-review-jump="{{ $i }}" aria-label="Question {{ $i + 1 }}: {{ $labels[$status] }}">{{ $i + 1 }}</a>@endforeach</div>
</nav>
<ul class="quiz-review-legend">@foreach(collect($review)->map(fn ($item) => $shown($item['status']))->countBy()->sortBy(fn ($count, $status) => array_search($status, array_keys($labels))) as $status => $count)<li><span class="quiz-review-swatch is-{{ $status }}" aria-hidden="true"></span>{{ $labels[$status] }}<strong>{{ $count }}</strong></li>@endforeach</ul>
</aside>
</div>
</div>
