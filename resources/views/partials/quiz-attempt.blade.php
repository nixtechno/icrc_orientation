<div class="quiz-attempt">
<div class="quiz-timer"><strong data-quiz-countdown></strong></div>
<header class="quiz-heading"><div><span class="eyebrow">ASSESSMENT / QUIZ</span><h1>{{ $activity->title }}</h1><p class="muted">Attempt {{ $submission->attempt }} &middot; {{ $activity->questions->count() }} questions</p></div></header>
<p class="quiz-active-rule"><strong>Stay in fullscreen until you submit. Exiting fullscreen submits this attempt automatically.</strong></p>
<p class="preline">{{ $activity->instructions }}</p><p class="muted" data-quiz-save-status role="status">Answers are saved as you work.</p>
<form class="card" method="post" action="/submissions/{{ $submission->getRouteKey() }}/complete" data-quiz-form>@csrf
<input type="hidden" name="draft_version" value="{{ $submission->draft_version }}">
@foreach($submission->shuffled($activity->questions) as $q)<fieldset><legend>{{ $loop->iteration }}. {{ $q->prompt }} ({{ $q->points }} points)</legend>
@if($q->type==='multiple_answers')<p class="muted">Select all correct answers. Full points require every correct option and no incorrect options.</p>@foreach($submission->shuffledOptions($q) as $option)<label><input type="checkbox" name="answers[{{ $q->id }}][]" value="{{ $option }}" @checked(in_array($option,(array)old('answers.'.$q->id,$submission->answers[$q->id]??[]),true))>{{ $option }}</label>@endforeach
@elseif(in_array($q->type,['multiple_choice','true_false']))@foreach($submission->shuffledOptions($q) as $option)<label><input type="radio" name="answers[{{ $q->id }}]" value="{{ $option }}" @checked(old('answers.'.$q->id,$submission->answers[$q->id]??null)===$option)>{{ $option }}</label>@endforeach
@else<label>Your response<textarea name="answers[{{ $q->id }}]" maxlength="10000">{{ old('answers.'.$q->id,$submission->answers[$q->id]??'') }}</textarea></label>@endif
</fieldset>@endforeach
<button type="submit" data-quiz-submit>Submit final answers</button><p class="muted">Submitting completes this attempt. Multiple-choice answers are marked straight away; written answers are marked by a facilitator.</p>
<dialog class="quiz-confirm" data-quiz-confirm aria-labelledby="quiz-confirm-title" aria-describedby="quiz-confirm-summary">
<h2 id="quiz-confirm-title">Submit your final answers?</h2>
<p id="quiz-confirm-summary" data-quiz-confirm-summary></p>
<p class="quiz-confirm-warning">You cannot change your answers after you submit.</p>
<div class="quiz-confirm-actions"><button type="button" class="secondary" data-quiz-confirm-cancel>Go back to my answers</button><button type="button" data-quiz-confirm-submit>Yes, submit</button></div>
</dialog>
</form></div>
