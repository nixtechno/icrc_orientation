@php($questions=$submission->shuffled($activity->questions))
@php($position=min((int)$submission->question_position,max(0,$questions->count()-1)))
@php($earlierAnswered=$questions->take($position)->filter(fn($q)=>filled($submission->answers[$q->id]??null))->count())
<div class="quiz-attempt">
<div class="quiz-timer"><strong data-quiz-countdown></strong></div>
<header class="quiz-heading"><div><span class="eyebrow">ASSESSMENT / QUIZ</span><h1>{{ $activity->title }}</h1><p class="muted">Attempt {{ $submission->attempt }} &middot; {{ $questions->count() }} questions</p></div></header>
<p class="quiz-active-rule"><strong>Stay in fullscreen until you submit. Exiting fullscreen submits this attempt automatically.</strong> Questions are shown one at a time. Once you move to the next question, you cannot go back to an earlier one.</p>
<p class="preline">{{ $activity->instructions }}</p><p class="muted" data-quiz-save-status role="status">Answers are saved as you work.</p>
<form class="card" method="post" action="/submissions/{{ $submission->getRouteKey() }}/complete" data-quiz-form data-quiz-total="{{ $questions->count() }}" data-quiz-earlier-answered="{{ $earlierAnswered }}">@csrf
<input type="hidden" name="draft_version" value="{{ $submission->draft_version }}">
<input type="hidden" name="position" value="{{ $position }}" data-quiz-position>
<p class="quiz-progress" data-quiz-progress aria-live="polite">Question {{ $position+1 }} of {{ $questions->count() }}</p>
@foreach($questions as $q)@continue($loop->index < $position)<fieldset data-quiz-question="{{ $loop->index }}" @if($loop->index !== $position) hidden @endif><legend>{{ $loop->iteration }}. {{ $q->prompt }} ({{ $q->points }} points)</legend>
@if($q->type==='multiple_answers')<p class="muted">Select all correct answers. Full points require every correct option and no incorrect options.</p>@foreach($submission->shuffledOptions($q) as $option)<label><input type="checkbox" name="answers[{{ $q->id }}][]" value="{{ $option }}" @checked(in_array($option,(array)old('answers.'.$q->id,$submission->answers[$q->id]??[]),true))>{{ $option }}</label>@endforeach
@elseif(in_array($q->type,['multiple_choice','true_false']))@foreach($submission->shuffledOptions($q) as $option)<label><input type="radio" name="answers[{{ $q->id }}]" value="{{ $option }}" @checked(old('answers.'.$q->id,$submission->answers[$q->id]??null)===$option)>{{ $option }}</label>@endforeach
@else<label>Your response<textarea name="answers[{{ $q->id }}]" maxlength="10000">{{ old('answers.'.$q->id,$submission->answers[$q->id]??'') }}</textarea></label>@endif
</fieldset>@endforeach
<div class="quiz-nav"><button type="button" data-quiz-next @if($position >= $questions->count()-1) hidden @endif>Next question</button><button type="submit" data-quiz-submit @if($position < $questions->count()-1) hidden @endif>Submit final answers</button></div>
<p class="muted" data-quiz-nav-note>You cannot return to this question after you move on.</p>
<p class="muted">Submitting completes this attempt. Multiple-choice answers are marked straight away; written answers are marked by a facilitator.</p>
<dialog class="quiz-confirm" data-quiz-skip aria-labelledby="quiz-skip-title">
<h2 id="quiz-skip-title">Move on without answering?</h2>
<p class="quiz-confirm-warning">You have not answered this question. If you move on, you cannot come back to it and it will score nothing.</p>
<div class="quiz-confirm-actions"><button type="button" class="secondary" data-quiz-skip-cancel>Stay on this question</button><button type="button" data-quiz-skip-confirm>Move on</button></div>
</dialog>
<dialog class="quiz-confirm" data-quiz-confirm aria-labelledby="quiz-confirm-title" aria-describedby="quiz-confirm-summary">
<h2 id="quiz-confirm-title">Submit your final answers?</h2>
<p id="quiz-confirm-summary" data-quiz-confirm-summary></p>
<p class="quiz-confirm-warning">You cannot change your answers after you submit.</p>
<div class="quiz-confirm-actions"><button type="button" class="secondary" data-quiz-confirm-cancel>Go back to this question</button><button type="button" data-quiz-confirm-submit>Yes, submit</button></div>
</dialog>
</form></div>
