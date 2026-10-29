@extends('layout')
@section('content')
<div class="page-heading"><div><div class="eyebrow">APPLY YOUR LEARNING</div><h1>{{ ['quiz'=>'Assessments','assignment'=>'Assignments','presentation'=>'Presentations'][request('kind')]??'Assessments & assignments' }}</h1><p class="muted">{{ $manager?'Create activities, manage submissions and release feedback.':'Your assigned work, deadlines and released feedback.' }}</p></div>@if($manager)<a class="button secondary" href="/assessment-groups">Manage staff groups</a>@endif</div>
<div class="buttons" style="margin-bottom:24px"><a href="/activities?kind=quiz">Assessments</a><a href="/activities?kind=assignment">Assignments</a><a href="/activities?kind=presentation">Presentations</a></div>
@if($manager)
<script src="{{ asset('activity-editor.js') }}?v=20261029b" defer></script>
<details class="card" @if($errors->any()) open @endif><summary>Create an activity</summary>
<form method="post" action="/activities" data-activity-editor style="margin-top:20px">@csrf
<div class="form-grid">
<label>Title<input name="title" value="{{ old('title') }}" maxlength="255" required></label>
<label>Kind<select name="kind">@foreach(['quiz'=>'Assessment','assignment'=>'Assignment','presentation'=>'Presentation'] as $kind=>$label)<option value="{{ $kind }}" @selected(old('kind',request('kind','quiz'))===$kind)>{{ $label }}</option>@endforeach</select></label>
<label>Available from<input type="datetime-local" name="available_at" value="{{ old('available_at') }}"></label>
<label>Due at<input type="datetime-local" name="due_at" value="{{ old('due_at') }}" required></label>
<label>Maximum attempts<input type="number" name="attempts" value="{{ old('attempts',1) }}" min="1" max="10" required></label>
<label>Time limit in minutes (optional)<input type="number" name="time_limit" value="{{ old('time_limit') }}" min="1" max="240"></label>
</div>
<div class="instruction-editor"><div class="dashboard-card-heading"><label for="activity-instructions">Instructions</label><button class="small secondary" type="button" data-dictation-toggle aria-pressed="false" aria-controls="activity-instructions">Use AI Speech to Text</button></div>
<textarea id="activity-instructions" name="instructions" rows="6" maxlength="20000" required>{{ old('instructions') }}</textarea>
<p class="muted" data-dictation-status role="status" aria-live="polite">Type your instructions or use your microphone to dictate. Review the text before saving.</p><p class="muted" data-dictation-preview aria-live="off"></p>
</div>
<div class="form-grid">
<label>Assignment audience<select name="assignment_mode"><option value="all" @selected(old('assignment_mode','all')==='all')>All enrolled staff</option><option value="group" @selected(old('assignment_mode')==='group')>A staff group</option></select></label>
<label data-group-field @if(old('assignment_mode','all')!=='group') hidden @endif>Staff group<select name="assessment_group_id" @disabled(old('assignment_mode','all')!=='group') @required(old('assignment_mode')==='group')><option value="">Choose a group</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('assessment_group_id')==$group->id)>{{ $group->name }} ({{ $group->members_count }} staff)</option>@endforeach</select><small><a href="/assessment-groups">Create or edit staff groups</a>. Group membership is copied when the activity is created.</small></label>
<label>Programme week (optional)<select name="programme_week_id"><option value="">Choose a week first</option>@foreach($weeks as $week)<option value="{{ $week->id }}" @selected(old('programme_week_id')==$week->id)>Week {{ $week->number }} &middot; {{ $week->theme }}</option>@endforeach</select></label>
<label>Session (optional)<select name="session_id" @disabled(!old('programme_week_id'))><option value="">{{ old('programme_week_id')?'Not linked to a session':'Choose a week first' }}</option>@foreach($weeks as $week)@foreach($week->days as $day)@foreach($day->sessions as $session)<option value="{{ $session->id }}" data-week="{{ $week->id }}" @if(old('programme_week_id')!=$week->id) hidden disabled @endif @selected(old('session_id')==$session->id&&old('programme_week_id')==$week->id)>{{ $day->date->format('j M') }} &middot; {{ $session->title??'To be confirmed' }}</option>@endforeach @endforeach @endforeach</select><small>Choose a week to see its sessions. Changing the week clears the selected session.</small></label>
</div><button>Create draft</button></form></details>
@endif
<div class="grid">@forelse($items as $a)<article class="card"><span class="badge">{{ $a->kind }}</span> @if(!$a->published)<span class="badge warning">Draft</span>@endif<h3 style="margin-top:16px">{{ $a->title }}</h3><p class="muted">Due {{ $a->due_at->format('j M Y') }} &middot; {{ $a->due_at->format('H:i') }}<br>{{ $a->attempts }} allowed attempt(s)</p>@if($manager&&$a->group)<p class="muted">Group: {{ $a->group->name }}</p>@endif<a href="/activities/{{ $a->getRouteKey() }}">{{ $manager?'Manage activity':'View activity' }} &rarr;</a></article>@empty<div class="empty full">No activities available in this view.</div>@endforelse</div>
@endsection