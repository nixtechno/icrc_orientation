{{-- Permanent deletion of a person, for administrators. Shows what goes with them, or why they can only be deactivated. --}}
@php($deletion = app(\App\Services\PersonDeletion::class))
@php($blocked = $deletion->blocker($user))
@php($counts = $deletion->counts($user))
<link rel="stylesheet" href="{{ asset('activity-manage.css') }}?v=20261102b">
<section class="card danger-zone" id="delete-person" aria-labelledby="delete-person-title">
<h2 id="delete-person-title">Delete this person</h2>
@if(\App\Services\AttendanceSync::venue())
<p class="muted">Delete people on the live site. The deletion reaches this venue on the next sync.</p>
@elseif($blocked)
<p class="muted">{{ $blocked }} Untick <strong>Active</strong> above and save to stop them signing in.</p>
@else
<p class="muted">Removes {{ $user->name }} ({{ $user->email }}) and their sign-in for good.@if($counts) Also removed: {{ collect($counts)->map(fn ($count, $what) => $count.' '.$what)->join(', ', ' and ') }}.@endif Scans or reviews they recorded for other people are kept. A backup of everything removed is saved on the server first.</p>
@if($errors->has('confirmed'))<div class="errors" role="alert">{{ $errors->first('confirmed') }}</div>@endif
<form method="post" action="/manage/staff/{{ $user->getRouteKey() }}">@csrf @method('DELETE')
<label><input type="checkbox" name="confirmed" value="1" required>I understand this permanently deletes {{ $user->name }} and cannot be undone.</label>
<button class="danger">Delete {{ $user->name }}</button>
</form>
@endif
</section>
