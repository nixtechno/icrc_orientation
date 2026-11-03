{{-- Required feedback after an assessment is submitted: a thank-you, four star ratings, then an optional comment. assessment-feedback.js steps through it. --}}
@php($steps=\App\Models\AssessmentFeedback::STEPS)@php($facilitators=$activity->facilitatorNames())@php($result=$submissions->whereNotNull('released_at')->sortByDesc('submitted_at')->first())
<link rel="stylesheet" href="{{ asset('assessment-feedback.css') }}?v=20261103c"><script src="{{ asset('assessment-feedback.js') }}?v=20261103c" defer></script>
<dialog class="feedback-dialog" data-feedback-dialog aria-labelledby="feedback-title">
<form method="post" action="/activities/{{ $activity->getRouteKey() }}/feedback" novalidate>@csrf
<header class="feedback-head"><span class="feedback-eyebrow">Programme feedback</span><p class="feedback-activity">{{ $activity->title }}</p>
<div class="feedback-progress" data-feedback-progress hidden><span class="feedback-count" data-feedback-count aria-live="polite"></span><ol aria-hidden="true">@foreach($steps as $step)<li data-feedback-dot></li>@endforeach<li data-feedback-dot></li></ol></div></header>
<div class="feedback-body">
<section class="feedback-step feedback-thanks" data-feedback-step><span class="feedback-tick" aria-hidden="true">&#10003;</span><h2 id="feedback-title">Assessment submitted</h2><p>Thank you for completing this assessment.</p>@if($result)<p class="feedback-score"><span>Your score</span><strong>{{ +$result->score }}<small> / {{ +$activity->questions->sum('points') }}</small></strong></p>@endif<p class="feedback-intro">Before you continue, please rate four parts of the programme. It takes under a minute. Organisers see the totals, not who gave them.</p></section>
@foreach($steps as $key=>$step)<fieldset class="feedback-step" data-feedback-step data-feedback-rating hidden><legend>{{ $step['title'] }}</legend>
<p class="feedback-question">{{ $step['question'] }}</p>@if($key==='facilitator'&&$facilitators)<p class="feedback-names">{{ implode(', ',$facilitators) }}</p>@endif
<div class="rating-stars">@foreach(\App\Models\AssessmentFeedback::SCALE as $value=>$word)<input class="rating-input" type="radio" id="feedback-{{ $key }}-{{ $value }}" name="{{ $key }}_rating" value="{{ $value }}" required @checked((int)old($key.'_rating')===$value)><label for="feedback-{{ $key }}-{{ $value }}" title="{{ $word }}"><span class="rating-star" aria-hidden="true">&#9733;</span><span class="rating-caption">{{ $word }}</span><span class="sr-only">{{ $value }} – {{ $word }}</span></label>@endforeach</div>
<output class="rating-word" data-rating-word></output>
<p class="feedback-error" data-feedback-error hidden>Choose a rating to continue.</p>
</fieldset>@endforeach
<section class="feedback-step" data-feedback-step hidden><h2 class="feedback-step-title">Anything else?</h2><p class="feedback-question">Is there anything else you would like to tell the organisers? This is optional.</p><label class="feedback-comment"><span class="sr-only">Comments</span><textarea name="comment" maxlength="3000" rows="5" placeholder="What worked well, and what could be better?" data-feedback-comment>{{ old('comment') }}</textarea><small class="feedback-chars" data-feedback-chars></small></label></section>
</div>
<div class="feedback-actions"><button type="button" class="secondary" data-feedback-back hidden>Back</button><span class="feedback-spacer"></span><button type="button" data-feedback-next>Continue</button><button data-feedback-submit hidden>Submit feedback</button></div>
</form></dialog>
