{{-- Who an activity is assigned to: everyone, a staff group or specific staff. --}}
@php($mode=old('assignment_mode',$mode??'all'))
@php($chosen=collect(old('participants',$chosen??[]))->map(fn($id)=>(int)$id)->all())
<div class="audience-fields" data-audience>
<label>Assign to<select name="assignment_mode"><option value="all" @selected($mode==='all')>All enrolled staff</option><option value="group" @selected($mode==='group')>A staff group</option><option value="selected" @selected($mode==='selected')>Specific staff</option></select></label>
<label data-group-field @if($mode!=='group') hidden @endif>Staff group<select name="assessment_group_id" @disabled($mode!=='group') @required($mode==='group')><option value="">Choose a group</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('assessment_group_id',$groupId??null)==$group->id)>{{ $group->name }} ({{ $group->members_count }} staff)</option>@endforeach</select><small><a href="/assessment-groups">Create or edit staff groups</a>. Group membership is copied when you save.</small></label>
<fieldset class="staff-picker" data-staff-field @if($mode!=='selected') hidden @endif @disabled($mode!=='selected')><legend>Staff to assign <span class="muted" data-staff-count>({{ count($chosen) }} selected)</span></legend>
<input type="search" placeholder="Search by name, staff number or department" aria-label="Search staff" data-staff-search>
<div class="staff-picker-list">@forelse($staffList as $person)<label data-staff-option data-search-text="{{ strtolower($person->name.' '.$person->staff_number.' '.$person->department) }}"><input type="checkbox" name="participants[]" value="{{ $person->id }}" @checked(in_array($person->id,$chosen,true))><span>{{ $person->name }}<small>{{ collect([$person->staff_number,$person->department])->filter()->join(' · ')?:'No staff number' }}</small></span></label>@empty<p class="muted">No staff are enrolled yet.</p>@endforelse<p class="muted" data-staff-empty hidden>No staff match this search.</p></div>
</fieldset>
</div>
