{{-- One submission and its review form, opened from the submissions table by review-dialogs.js. --}}
@php($zone=\App\Services\Access::programme()->timezone)
<dialog class="review-dialog" id="submission-{{ $s->getRouteKey() }}" aria-labelledby="review-title-{{ $s->getRouteKey() }}">
<div class="review-dialog-head"><div><span class="eyebrow">{{ $activity->title }}</span><h2 id="review-title-{{ $s->getRouteKey() }}">{{ $s->user->name }}</h2><p class="muted">{{ collect([$s->user->staff_number,$s->user->department])->filter()->join(' · ')?:'No staff number' }}</p></div><button type="button" class="link review-dialog-close" data-review-close aria-label="Close">&times;</button></div>
<div class="review-dialog-body">
<p class="review-dialog-meta">{{ $s->submitted_at?'Submitted '.$s->submitted_at->timezone($zone)->format('j M Y · H:i'):'Started; not submitted' }}@if($activity->kind!=='presentation') &middot; Attempt {{ $s->attempt }}@endif @if($activity->due_at&&$s->submitted_at?->gt($activity->due_at))<span class="badge warning">Late</span>@endif @if($s->submission_reason&&$s->submission_reason!=='manual')<span class="badge warning">Automatically submitted &middot; {{ ucwords(str_replace('_',' ',$s->submission_reason)) }}</span>@endif</p>
@if($s->path)<p class="review-dialog-file"><a href="/submissions/{{ $s->getRouteKey() }}/download">Download {{ $s->filename??'submitted file' }}</a></p>@endif
@if(filled($s->body))<h3>{{ $activity->kind==='presentation'?'Presentation text':'Written submission' }}</h3><div class="review-dialog-text preline">{{ $s->body }}</div>@endif
@foreach($activity->questions as $q)<p><strong>{{ $q->prompt }}</strong><br>{{ is_array($s->answers[$q->id]??null)?implode(', ',$s->answers[$q->id]):($s->answers[$q->id]??'No response') }}</p>@endforeach
@if($s->submitted_at)<form method="post" action="/submissions/{{ $s->getRouteKey() }}/review" class="review-dialog-form">@csrf<h3>Review</h3>
@if($activity->kind==='quiz')
@php($marking=app(\App\Services\QuizAttemptService::class))
@php($autoMarks=$marking->autoScore($activity,$s->answers??[]))
@php($writtenMarks=$marking->writtenPoints($activity))
@php($autoTotal=$activity->questions->sum('points')-$writtenMarks)
@if($autoTotal)<p class="review-dialog-auto"><strong>Marked automatically: {{ +$autoMarks }} / {{ +$autoTotal }}</strong><br><span class="muted">Set by the system from the answers. It cannot be changed.</span></p>@endif
@if($writtenMarks)<label>Marks for written answers (out of {{ $writtenMarks }})<input type="number" step="0.01" min="0" max="{{ $writtenMarks }}" name="written_score" value="{{ $s->score!==null?max(0,+$s->score-$autoMarks):'' }}" required><small>Added to the automatic marks for the total score.</small></label>@endif
@else
<label>Score<input type="number" step="0.01" min="0" name="score" value="{{ $s->score }}" required></label>
@endif
<label>Feedback<textarea name="feedback" rows="4">{{ $s->feedback }}</textarea></label><label><input type="checkbox" name="release" value="1" @checked($s->released_at)>Release score and feedback to participant</label>
<div class="buttons"><button type="button" class="secondary" data-review-close>Cancel</button><button>Save review</button></div></form>
@else<p class="notice">This attempt has not been submitted yet, so it cannot be reviewed.</p>@endif
</div>
</dialog>
