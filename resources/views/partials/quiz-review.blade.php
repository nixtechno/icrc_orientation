{{-- A submitted assessment, one question at a time with a numbered map. Correct answers and explanations appear only once the result is released. --}}
@php
    $review = app(\App\Services\QuizAttemptService::class)->review($activity, $s);
    $released = (bool) $s->released_at;
    $explained = \App\Models\Question::hasExplanations();
    $total = $activity->questions->sum('points');
    $labels = ['correct' => 'Correct', 'wrong' => 'Incorrect', 'unanswered' => 'Not answered', 'written' => 'Written answer', 'answered' => 'Answered'];
    // Before release only show what was answered, never whether it was right.
    $shown = fn ($status) => $released || in_array($status, ['unanswered', 'written'], true) ? $status : 'answered';
    $prefix = 'q'.$s->getRouteKey().'-';
@endphp
<div class="quiz-review" data-quiz-review>
@unless($released && $s->score !== null)
<p class="notice quiz-review-pending" role="status"><strong>Your answers are with a facilitator for marking.</strong> Your score and the correct answers appear here once your result is released.</p>
@endunless
<div class="quiz-review-layout">
<div class="quiz-review-main">
@foreach($review as $i => $item)
@php($q = $item['question'])
@php($status = $shown($item['status']))
<article class="quiz-review-question" id="{{ $prefix.($i + 1) }}" data-review-question tabindex="-1" aria-labelledby="{{ $prefix.($i + 1) }}-title">
<header class="quiz-review-question-head"><span class="quiz-review-number">Question {{ $i + 1 }} of {{ count($review) }}</span><span class="quiz-review-status is-{{ $status }}">{{ $labels[$status] }}</span></header>
<h3 class="quiz-review-prompt preline" id="{{ $prefix.($i + 1) }}-title">{{ $q->prompt }}</h3>
<p class="quiz-review-marks">@if($released && in_array($item['status'], ['correct', 'wrong', 'unanswered'], true)){{ $item['status'] === 'correct' ? $q->points : 0 }} / {{ $q->points }} {{ $q->points == 1 ? 'mark' : 'marks' }}@else{{ $q->points }} {{ $q->points == 1 ? 'mark' : 'marks' }}@endif @if($q->type === 'multiple_answers') &middot; Select all that apply @endif</p>
@if($q->options)
<ol class="quiz-review-options">
@foreach($q->options as $o => $option)
@php($mine = in_array($option, $item['selected'], true))
@php($right = $released && in_array($option, $item['correct'], true))
@php($state = $released ? ($mine ? ($right ? 'correct' : 'wrong') : ($right ? 'answer' : '')) : ($mine ? 'selected' : ''))
<li class="quiz-review-option {{ $state ? 'is-'.$state : '' }}"><span class="quiz-review-letter" aria-hidden="true">{{ chr(65 + $o) }}</span><span class="quiz-review-text">{{ $option }}</span>
@if($state)<span class="quiz-review-tag">{{ match($state) { 'correct' => 'Your answer · Correct', 'wrong' => 'Your answer · Incorrect', 'answer' => 'Correct answer', default => 'Your answer' } }}</span>@endif
<span class="quiz-review-icon" aria-hidden="true">{{ match($state) { 'correct', 'answer' => '✓', 'wrong' => '✕', default => '' } }}</span></li>
@endforeach
</ol>
@if($item['status'] === 'unanswered')<p class="quiz-review-note">You did not answer this question.</p>@endif
@else
<div class="quiz-review-response"><span>Your response</span>@if($item['response'] !== '')<p class="preline">{{ $item['response'] }}</p>@else<p class="muted">No response.</p>@endif</div>
<p class="quiz-review-note">Written answers are marked by a facilitator as part of your overall score.</p>
@endif
@if($released && $explained && filled($q->explanation))<div class="quiz-review-explanation"><strong>Explanation</strong><p class="preline">{{ $q->explanation }}</p></div>@endif
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
