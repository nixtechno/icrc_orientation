@extends('layout')
@section('content')
@php($createLabel=match($selectedKind){'assignment'=>'Create an Assignment','presentation'=>'Create a Presentation',default=>'Create an Assessment'})
<div class="page-heading"><div><div class="eyebrow">APPLY YOUR LEARNING</div><h1>{{ ['quiz'=>'Assessments','assignment'=>'Assignments','presentation'=>'Presentations'][$selectedKind] }}</h1><p class="muted">{{ $manager?'Create activities, manage submissions and release feedback.':'Your assigned work, deadlines and released feedback.' }}</p></div>@if($manager)<a class="button secondary" href="/assessment-groups">Manage staff groups</a>@endif</div>
<div class="buttons" style="margin-bottom:24px"><a href="/activities?kind=quiz">Assessments</a><a href="/activities?kind=assignment">Assignments</a><a href="/activities?kind=presentation">Presentations</a></div>
@if($tabbed)<nav class="view-tabs" aria-label="Presentation views"><a href="/activities?kind=presentation" @if($view==='activities') aria-current="page" @endif><strong>Presentations</strong><span>{{ $items->count() }}</span></a><a href="/activities?kind=presentation&amp;view=submissions" @if($view==='submissions') aria-current="page" @endif><strong>Submitted presentations</strong><span>{{ $submittedTotal }}</span></a></nav>@endif
@unless($manager)<link rel="stylesheet" href="{{ asset('activity-manage.css') }}?v=20261102b">@endunless
@if($manager)
<script src="{{ asset('activity-editor.js') }}?v=20261101a" defer></script><script src="{{ asset('audience-picker.js') }}?v=20261101a" defer></script><script src="{{ asset('activity-delete.js') }}?v=20261101a" defer></script><link rel="stylesheet" href="{{ asset('activity-manage.css') }}?v=20261102b">
@if($view==='activities')<details class="card" @if($errors->any()) open @endif><summary>{{ $createLabel }}</summary>
<form method="post" action="/activities?kind={{ $selectedKind }}" data-activity-editor style="margin-top:20px">@csrf
<div class="form-grid">
<label>Title<input name="title" value="{{ old('title') }}" maxlength="255" required></label>
<input type="hidden" name="kind" value="{{ $selectedKind }}">
<label>Due at<input type="datetime-local" name="due_at" value="{{ old('due_at') }}" required></label>
@if($selectedKind==='quiz')
<label>Available from<input type="datetime-local" name="available_at" value="{{ old('available_at') }}"></label>

<label>Maximum attempts<input type="number" name="attempts" value="{{ old('attempts',1) }}" min="1" max="10" required></label>
<label>Time limit in minutes (optional)<input type="number" name="time_limit" value="{{ old('time_limit') }}" min="1" max="240"></label>@endif
</div>
<div class="instruction-editor"><div class="dashboard-card-heading"><label for="activity-instructions">Instructions</label><button class="small secondary" type="button" data-dictation-toggle aria-pressed="false" aria-controls="activity-instructions">Use AI Speech to Text</button></div>
<textarea id="activity-instructions" name="instructions" rows="6" maxlength="20000" required>{{ old('instructions') }}</textarea>
<p class="muted" data-dictation-status role="status" aria-live="polite">Type your instructions or use your microphone to dictate. Review the text before saving.</p><p class="muted" data-dictation-preview aria-live="off"></p>
</div>
@include('partials.audience-fields')
<div class="form-grid">
<label>Programme week (optional)<select name="programme_week_id"><option value="">Choose a week first</option>@foreach($weeks as $week)<option value="{{ $week->id }}" @selected(old('programme_week_id')==$week->id)>Week {{ $week->number }} &middot; {{ $week->theme }}</option>@endforeach</select></label>
<label>Session (optional)<select name="session_id" @disabled(!old('programme_week_id'))><option value="">{{ old('programme_week_id')?'Not linked to a session':'Choose a week first' }}</option>@foreach($weeks as $week)@foreach($week->days as $day)@foreach($day->sessions as $session)<option value="{{ $session->id }}" data-week="{{ $week->id }}" @if(old('programme_week_id')!=$week->id) hidden disabled @endif @selected(old('session_id')==$session->id&&old('programme_week_id')==$week->id)>{{ $day->date->format('j M') }} &middot; {{ $session->title??'To be confirmed' }}</option>@endforeach @endforeach @endforeach</select><small>Choose a week to see its sessions. Changing the week clears the selected session.</small></label>
</div><button>Create draft</button></form></details>@endif
@endif
@if($presentationTable)@include('partials.presentation-submissions',$presentationTable)@else
<div class="grid">@forelse($items as $a)<article class="card activity-card">@if(!$manager&&$a->kind==='quiz')@include('partials.assessment-card-score')@endif<h3 class="activity-card-title">{{ $a->title }}</h3><p class="muted activity-card-due">{{ $a->due_at?'Due '.$a->due_at->format('j M Y · H:i'):'No deadline' }}@unless($manager)<br>{{ match($a->kind){'quiz'=>'One submission only','presentation'=>'PowerPoint file or written text',default=>$a->attempts.' allowed attempt(s)'} }}@endunless</p>
<div class="activity-card-badges"><span class="badge">{{ $a->label() }}</span>
@if(!$manager && in_array($a->kind, ['quiz', 'presentation'], true))
@include('partials.assessment-card-status')
@endif
 @if(!$a->published)<span class="badge warning">Draft</span>@elseif($manager)<span class="badge">Published</span>@endif @if($manager&&$a->due_at&&now()->gt($a->due_at))<span class="badge muted-badge">Closed</span>@endif</div>@if($manager)@include('partials.activity-progress',['activity'=>$a,'assigned'=>$a->participants_count,'counts'=>$stats->get($a->id)])<p class="muted activity-card-audience">Assigned to: @include('partials.audience-summary',['activity'=>$a,'count'=>$a->participants_count])</p><div class="activity-card-actions"><a href="/activities/{{ $a->getRouteKey() }}">Manage activity &rarr;</a>{{-- Deleting assessments is hidden on the cards for now; it is still on each assessment's page. --}}@unless($a->kind==='quiz')@include('partials.delete-activity-button',['activity'=>$a])@endunless</div>@else<div class="activity-card-actions">@include('partials.staff-activity-action')</div>@endif</article>@empty<div class="empty full">No activities available in this view.</div>@endforelse</div>
@endif
@if($manager)@include('partials.delete-activity-dialog')@endif
@endsection