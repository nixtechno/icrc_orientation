{{-- Today's training feedback: opens on the portal at the feedback time set in Data tools and is compulsory, so it cannot be closed
     until it is sent. Once a day per person. training-feedback.js times the opening and steps through the form. --}}
@php($steps=\App\Models\TrainingFeedback::STEPS)@php($day=\App\Models\TrainingFeedback::today(\App\Services\Access::programme()))@php($facilitators=\App\Models\Facilitator::whereHas('sessions', fn ($q) => $q->where('programme_day_id', $day->id))->orderBy('name')->pluck('name')->all())
<link rel="stylesheet" href="{{ asset('training-feedback.css') }}?v=20261103h"><script src="{{ asset('training-feedback.js') }}?v=20261103h" defer></script>
<dialog class="feedback-dialog" data-feedback-dialog data-opens-at="{{ $opens->toIso8601String() }}" data-server-now="{{ now()->toIso8601String() }}" data-day="{{ $day->date->toDateString() }}" aria-labelledby="feedback-title">
<form method="post" action="/training-feedback" novalidate>@csrf
<header class="feedback-head"><span class="feedback-eyebrow">Daily training feedback</span><p class="feedback-activity">{{ $day->date->format('l j F Y') }}</p>
<div class="feedback-progress" data-feedback-progress hidden><span class="feedback-count" data-feedback-count aria-live="polite"></span><ol aria-hidden="true">@foreach($steps as $step)<li data-feedback-dot></li>@endforeach<li data-feedback-dot></li></ol></div></header>
<div class="feedback-body">
<section class="feedback-step feedback-thanks" data-feedback-step><span class="feedback-tick" aria-hidden="true">&#9733;</span><h2 id="feedback-title">How was today's training?</h2><p class="feedback-important">Your feedback is very important to us.</p><p class="feedback-intro">Please rate today's training based on your experience. Your feedback will help us improve future sessions.</p><p class="feedback-meta">Estimated completion time: 2 minutes</p><p class="feedback-scale">1 – Poor &middot; 2 – Fair &middot; 3 – Good &middot; 4 – Very good &middot; 5 – Excellent</p></section>
@foreach($steps as $key=>$step)<fieldset class="feedback-step" data-feedback-step data-feedback-rating hidden><legend>{{ $step['title'] }}</legend>
<p class="feedback-question">{{ $step['question'] }}</p>@if($key==='facilitator'&&$facilitators)<p class="feedback-names">{{ implode(', ',$facilitators) }}</p>@endif
<div class="rating-stars">@foreach(\App\Models\TrainingFeedback::SCALE as $value=>$word)<input class="rating-input" type="radio" id="feedback-{{ $key }}-{{ $value }}" name="{{ $key }}_rating" value="{{ $value }}" required @checked((int)old($key.'_rating')===$value)><label for="feedback-{{ $key }}-{{ $value }}" title="{{ $word }}"><span class="rating-star" aria-hidden="true">&#9733;</span><span class="rating-caption">{{ $word }}</span><span class="sr-only">{{ $value }} – {{ $word }}</span></label>@endforeach</div>
<output class="rating-word" data-rating-word></output>
<p class="feedback-error" data-feedback-error hidden>Choose a rating to continue.</p>
</fieldset>@endforeach
<section class="feedback-step" data-feedback-step hidden><h2 class="feedback-step-title">Your suggestions</h2><p class="feedback-question">What can we do to improve your training experience? This is optional.</p><label class="feedback-comment"><span class="sr-only">Comments</span><textarea name="comment" maxlength="3000" rows="5" placeholder="Your suggestions" data-feedback-comment>{{ old('comment') }}</textarea><small class="feedback-chars" data-feedback-chars></small></label></section>
</div>
<div class="feedback-actions"><button type="button" class="secondary" data-feedback-back hidden>Back</button><span class="feedback-spacer"></span><button type="button" data-feedback-next>Continue</button><button data-feedback-submit hidden>Submit feedback</button></div>
</form></dialog>
