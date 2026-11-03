@extends('layout')

@section('content')<div class="page-heading"><div><span class="eyebrow">{{ $activity->label() }}@if($manager) · {{ $activity->published?'PUBLISHED':'DRAFT' }}@endif</span><h1>{{ $activity->title }}</h1><p class="muted">{{ $activity->due_at?'Due '.$activity->due_at->format('j F Y · H:i'):'No deadline' }}{{ $activity->kind==='presentation'?'':' · '.$activity->attempts.' attempt(s)' }}{{ $activity->time_limit?' · '.$activity->time_limit.' minute limit':'' }}</p></div><div class="buttons">@if($manager)<form method="post" action="/activities/{{ $activity->getRouteKey() }}/publish">@csrf<button class="{{ $activity->published?'secondary':'' }}">{{ $activity->published?'Unpublish':'Publish' }} {{ $activity->label() }}</button></form>@endif</div></div>
@if(!$manager)
{{-- Once an assessment is submitted and marked there is nothing left to start, so only the result is shown. --}}
@unless($activity->kind==='quiz'&&$submissions->whereNotNull('released_at')->isNotEmpty())<section class="card"><h2>Instructions</h2><p class="preline">{{ $activity->instructions }}</p>
@if($activity->kind==='quiz')
@include('partials.quiz-launch')
@elseif($activity->kind==='presentation')
@include('partials.presentation-submit')
@else<form method="post" action="/activities/{{ $activity->getRouteKey() }}/start">@csrf<button>Start / continue an attempt</button></form>
@endif</section>@endunless
@else<link rel="stylesheet" href="{{ asset('activity-manage.css') }}?v=20261101i"><script src="{{ asset('audience-picker.js') }}?v=20261101a" defer></script><script src="{{ asset('activity-delete.js') }}?v=20261101a" defer></script>
<div class="manage-grid">
<section class="card"><h2>Instructions</h2><p class="preline">{{ $activity->instructions }}</p>
<details @if($errors->hasAny(['title','instructions','due_at'])) open @endif><summary>Edit details</summary><form method="post" action="/activities/{{ $activity->getRouteKey() }}" style="margin-top:16px">@csrf
<label>Title<input name="title" value="{{ old('title',$activity->title) }}" maxlength="255" required></label>
<label>Due at<input type="datetime-local" name="due_at" value="{{ old('due_at',$activity->due_at?->format('Y-m-d\TH:i')) }}" required></label>
<label>Instructions<textarea name="instructions" rows="6" maxlength="20000" required>{{ old('instructions',$activity->instructions) }}</textarea></label>
<button>Save details</button></form></details></section>
@php($assigned=$activity->participants->sortBy('name'))
<section class="card"><h2>Assigned to</h2><p><strong>@include('partials.audience-summary',['count'=>$assigned->count()])</strong></p>
@if($assigned->isNotEmpty())<details @if($assigned->count()<=12) open @endif><summary>{{ $assigned->count()===1?'Show the assigned person':'Show all '.$assigned->count().' assigned staff' }}</summary><ul class="assigned-people">@foreach($assigned as $person)<li>{{ $person->name }}</li>@endforeach</ul></details>@endif
<details @if($errors->hasAny(['assignment_mode','assessment_group_id','participants'])) open @endif><summary>Change who is assigned</summary><form method="post" action="/activities/{{ $activity->getRouteKey() }}/assignment" style="margin-top:16px">@csrf
@include('partials.audience-fields',['mode'=>$activity->audienceMode(),'groupId'=>$activity->assessment_group_id,'chosen'=>$assigned->pluck('id')->all()])
<p class="muted">Staff who have already started or submitted stay assigned so they keep their work and results.</p><button>Save assignment</button></form></details></section>
</div>
@endif

@if($manager&&$activity->kind==='presentation')@php($submittedIds=$submissions->whereNotNull('submitted_at')->pluck('user_id'))@php($waiting=$activity->participants->whereNotIn('id',$submittedIds)->sortBy('name'))
<section class="card"><h2>Submission progress</h2>@include('partials.activity-progress',['assigned'=>$activity->participants->count(),'counts'=>(object)['submitted'=>$submittedIds->unique()->count(),'reviewed'=>$submissions->whereNotNull('submitted_at')->whereNotNull('score')->pluck('user_id')->unique()->count()]])
@if($waiting->isNotEmpty())<details class="pending-people"><summary>Not yet submitted ({{ $waiting->count() }})</summary><ul class="assigned-people">@foreach($waiting as $person)<li>{{ $person->name }}</li>@endforeach</ul></details>@endif</section>
@include('partials.presentation-submissions',$presentationTable)
@endif
@if($manager&&$activity->kind==='quiz')<section class="card"><h2>Questions</h2>
@foreach($activity->questions as $q)<div class="activity-row"><div><strong>{{ $q->prompt }}</strong><p>{{ $q->type }} · {{ $q->points }} points · Correct answer: {{ $q->type==='multiple_answers'?implode(', ',$q->correctAnswers()):($q->correct_answer??'Manual review') }}</p><p>{{ implode(' / ',$q->options??[]) }}</p>@if(\App\Models\Question::hasExplanations()&&filled($q->explanation))<p class="muted"><strong>Explanation:</strong> {{ $q->explanation }}</p>@endif</div></div>
@endforeach
@unless($activity->published)<details><summary>Add question</summary><form method="post" action="/activities/{{ $activity->getRouteKey() }}/questions">@csrf<label>Type<select name="type">
@foreach(['multiple_choice','multiple_answers','true_false','short_answer','long_answer'] as $type)<option value="{{ $type }}">{{ $type==='multiple_answers'?'Multiple Choice Multiple Answers':ucwords(str_replace('_',' ',$type)) }}</option>
@endforeach</select></label><label>Question<textarea name="prompt" required></textarea></label><label>Options (one per line; True / False supplied automatically)<textarea name="options"></textarea></label><label>Correct answer(s)<textarea name="correct_answer"></textarea><small>Use exact option text. For Multiple Choice Multiple Answers, enter each correct option on a separate line (at least two). All correct options must be selected for full points.</small></label><label>Points<input type="number" name="points" value="1" min="1" max="100" required></label>@if(\App\Models\Question::hasExplanations())<label>Explanation (optional)<textarea name="explanation" maxlength="5000"></textarea><small>Why the correct answer is right. Staff see it beside their answer once their result is released.</small></label>@endif<button>Add question</button></form></details>
@include('partials.question-import')
@endunless</section>
@endif
@if($manager)
@unless($activity->kind==='presentation')<section class="card"><div class="submissions-card-head"><h2>Submissions &amp; reviews</h2><span class="muted">{{ $submissions->count() }} {{ $submissions->count()===1?'attempt':'attempts' }}</span></div>
@if($submissions->isEmpty())<div class="empty">No attempts yet.</div>@else<div class="table-wrap"><table><thead><tr><th>Staff</th><th>Attempt</th><th>Submitted</th><th>Score</th><th>Review status</th><th>Actions</th></tr></thead><tbody>
@foreach($submissions->sortByDesc('submitted_at') as $s)<tr><td>{{ $s->user->name }}<div class="meta">{{ $s->user->staff_number }}</div></td><td>{{ $s->attempt }}</td><td>{{ $s->submitted_at?$s->submitted_at->timezone(\App\Services\Access::programme()->timezone)->format('j M Y · H:i'):'Not submitted' }}@if($activity->due_at&&$s->submitted_at?->gt($activity->due_at))<div class="meta">Late</div>@endif</td><td>{{ $s->score??'—' }}</td><td><span class="badge {{ !$s->released_at?'warning':'' }}">{{ !$s->submitted_at?'In progress':($s->released_at?'Result released':($s->score!==null?'Awaiting release':'Awaiting grading')) }}</span></td><td><a href="#submission-{{ $s->getRouteKey() }}" data-review-open>{{ $s->submitted_at?'Review':'View' }}</a><form method="post" action="/activities/{{ $activity->getRouteKey() }}/reset/{{ $s->user->getRouteKey() }}" style="display:inline;margin:0 0 0 12px;padding:0;background:none;border:0;box-shadow:none" onsubmit="return confirm('Reset {{ e(addslashes($s->user->name)) }}? All their attempts and answers are deleted so they can take it again.')">@csrf<button class="secondary small">Reset</button></form></td></tr>@endforeach
</tbody></table></div>@endif</section>@endunless
<script src="{{ asset('review-dialogs.js') }}?v=20261101a" defer></script>
@foreach($submissions as $s)@include('partials.review-dialog')@endforeach
@else
@if($activity->kind==='quiz')<link rel="stylesheet" href="{{ asset('quiz-review.css') }}?v=20261102b"><script src="{{ asset('quiz-review.js') }}?v=20261102a" defer></script>@endif
<h2>My submissions</h2>
@forelse($submissions as $s)<section class="card" id="submission-{{ $s->getRouteKey() }}"><h3>{{ $activity->kind==='presentation'?'My presentation':'My attempt' }}@unless($activity->kind==='presentation') · Attempt {{ $s->attempt }}@endunless</h3><p class="muted">{{ $s->submitted_at?'Submitted '.$s->submitted_at->format('j M H:i'):'Started; not submitted' }}{{ ($activity->due_at&&$s->submitted_at?->gt($activity->due_at))?' · Late':'' }}</p>
@if($s->submitted_at)
@if($s->submission_reason&&$s->submission_reason!=='manual')<p class="badge warning">Automatically submitted &middot; {{ ucwords(str_replace('_',' ',$s->submission_reason)) }}</p>
@endif
@if($activity->kind==='quiz')
@include('partials.quiz-review')
@if($s->released_at&&filled($s->feedback))<div class="quiz-review-feedback"><strong>Facilitator feedback</strong><p class="preline">{{ $s->feedback }}</p></div>@endif
@else<p class="preline">{{ $s->body }}</p>
@if($s->path)<p><a href="/submissions/{{ $s->getRouteKey() }}/download">Download submitted file</a></p>
@endif
@if($s->released_at)<p><strong>Score: {{ +$s->score }}</strong></p>@if(filled($s->feedback))<p class="preline">{{ $s->feedback }}</p>@endif
@else<p class="badge warning">Awaiting review / result release</p>
@endif
@endif
@endif</section>
@empty<div class="empty">{{ $activity->kind==='presentation'?'No submissions yet.':'No attempts yet.' }}</div>
@endforelse
@endif
@if($manager)<section class="card danger-zone"><h2>Delete {{ $activity->label() }}</h2><p class="muted">Removes it, its assignments and all its submissions and uploaded files. This cannot be undone.</p>@include('partials.delete-activity-button',['buttonClass'=>'danger','buttonText'=>'Delete this '.$activity->label()])</section>
@include('partials.delete-activity-dialog')@endif
@endsection
