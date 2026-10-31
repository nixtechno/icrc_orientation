<div class="quiz-attempt">
<header class="quiz-heading"><div><span class="eyebrow">ASSESSMENT / QUIZ</span><h1>{{ $activity->title }}</h1><p class="muted">Attempt {{ $submission->attempt }} &middot; {{ $activity->questions->count() }} questions</p></div><strong data-quiz-countdown></strong></header>
<p class="quiz-active-rule"><strong>Stay in fullscreen until you submit. Exiting fullscreen submits this attempt automatically.</strong></p>
<p class="preline">{{ $activity->instructions }}</p><p class="muted" data-quiz-save-status role="status">Answers are saved as you work.</p>
<form class="card" method="post" action="/submissions/{{ $submission->getRouteKey() }}/complete" data-quiz-form>@csrf
<input type="hidden" name="draft_version" value="{{ $submission->draft_version }}">
@foreach($activity->questions as $q)<fieldset><legend>{{ $loop->iteration }}. {{ $q->prompt }} ({{ $q->points }} points)</legend>
@if($q->type==='multiple_answers')<p class="muted">Select all correct answers. Full points require every correct option and no incorrect options.</p>@foreach($q->options as $option)<label><input type="checkbox" name="answers[{{ $q->id }}][]" value="{{ $option }}" @checked(in_array($option,(array)old('answers.'.$q->id,$submission->answers[$q->id]??[]),true))>{{ $option }}</label>@endforeach
@elseif(in_array($q->type,['multiple_choice','true_false']))@foreach($q->options as $option)<label><input type="radio" name="answers[{{ $q->id }}]" value="{{ $option }}" @checked(old('answers.'.$q->id,$submission->answers[$q->id]??null)===$option)>{{ $option }}</label>@endforeach
@else<label>Your response<textarea name="answers[{{ $q->id }}]" maxlength="10000">{{ old('answers.'.$q->id,$submission->answers[$q->id]??'') }}</textarea></label>@endif
</fieldset>@endforeach
<button type="submit" data-quiz-submit>Submit final answers</button><p class="muted">Submitting completes this attempt. Results are shown after facilitator release.</p>
</form></div>
