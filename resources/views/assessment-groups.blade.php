@extends('layout')
@section('content')
<script src="{{ asset('staff-groups.js') }}?v=20261029a" defer></script>
<div class="page-heading"><div><span class="eyebrow">LEARNING TOGETHER</span><h1>Staff groups</h1><p class="muted">Create reusable groups for assessments, assignments and presentations.</p></div><a class="button secondary" href="/activities">Create an activity</a></div>
<p class="notice">Changing a group affects future activities. Staff already assigned to an activity keep their access and submissions.</p>
@php($group=$editingGroup)
<details class="card" open>
<summary>{{ $group?'Edit '.$group->name:'Create a staff group' }}</summary>
<form method="post" action="/assessment-groups{{ $group?'/'.$group->getRouteKey():'' }}" data-staff-group style="margin-top:20px">@csrf
<input type="hidden" name="group_form" value="{{ $group?->getRouteKey()??'new' }}">
@php($restore=old('group_form')===($group?->getRouteKey()??'new'))
<label>Group name<input name="name" maxlength="150" required value="{{ $restore?old('name'):$group?->name }}"></label>
<fieldset><legend>Enrolled staff</legend>
<label>Find staff<input type="search" data-member-search placeholder="Search name or department" autocomplete="off"></label>
<p class="muted" data-member-count role="status"></p>
<div class="staff-group-members">
@forelse($staff as $person)<label class="staff-group-member" data-member-label><input type="checkbox" name="members[]" value="{{ $person->id }}" @checked(in_array($person->id,$restore?(array)old('members',[]):($group?->members->modelKeys()??[])))><span><strong>{{ $person->name }}</strong><small>{{ $person->department??'Department not specified' }}{{ !$person->active?' · Awaiting activation':'' }}</small></span></label>@empty<p class="empty">No staff are enrolled yet.</p>@endforelse
</div><p class="muted" data-member-empty hidden>No staff match your search.</p>
</fieldset><button>{{ $group?'Save group':'Create group' }}</button>
</form></details>
<section><h2>Available groups</h2><div class="grid">@forelse($groups as $savedGroup)<article class="card"><h3>{{ $savedGroup->name }}</h3><p class="muted">{{ $savedGroup->members_count }} enrolled staff</p><a href="/assessment-groups/{{ $savedGroup->getRouteKey() }}/edit">Edit group &rarr;</a></article>@empty<p class="empty">Create your first group to assign work to a team.</p>@endforelse</div></section>
@endsection
