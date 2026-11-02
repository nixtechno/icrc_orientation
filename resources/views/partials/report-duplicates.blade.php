{{-- Profiles that share a first name and surname. An administrator keeps one and merges the rest into it, or marks them as different people. --}}
<section class="card report-section" id="duplicates" aria-labelledby="duplicates-title">
<h2 id="duplicates-title">Possible duplicate profiles</h2>
<p class="muted">Profiles with the same first name and surname. Keep the right one and merge the others into it: their attendance, submissions, badge, enrolments and history move across, and the duplicate profile is removed. The kept profile's email, password and role stay as they are.</p>
@if(!$canMerge)<p class="notice">{{ \App\Services\AttendanceSync::venue() ? 'Reconcile duplicates on the live site. Merges reach this venue on the next sync.' : 'Only administrators can merge profiles.' }}</p>@endif
@forelse($duplicates as $group)
<article class="report-duplicate">
<h3>{{ $group['people'][0]['user']->name }} <span class="muted">· {{ count($group['people']) }} profiles</span></h3>
<form method="post" action="/reports/duplicates/merge">@csrf<input type="hidden" name="signature" value="{{ $group['signature'] }}">
<div class="table-wrap"><table><thead><tr>@if($canMerge)<th scope="col">Keep</th>@endif<th scope="col">Profile</th><th scope="col">Role</th><th scope="col">Account</th><th scope="col">Staff number</th><th scope="col">Department</th><th scope="col">Badge</th><th scope="col">Attendance days</th><th scope="col">Submissions</th></tr></thead><tbody>
@foreach($group['people'] as $i => $p)@php($u = $p['user'])
<tr>@if($canMerge)<td><input type="radio" name="keep" value="{{ $u->id }}" @checked($i === 0) aria-label="Keep {{ $u->name }} ({{ $u->email }})" required></td>@endif
<td><strong>{{ $u->name }}</strong><div class="meta">{{ $u->email }}</div>@unless($p['enrolled'])<div class="meta">Not enrolled in this programme</div>@endunless</td>
<td>{{ ucfirst($u->role) }}</td><td>{{ $u->active ? 'Activated' : 'Not activated' }}</td><td>{{ $u->staff_number ?: '—' }}</td><td>{{ $u->department ?: '—' }}</td>
<td>{{ $p['badge'] ? ($p['badge']->collected_at ? 'Collected' : ($p['badge']->printed_at ? 'Printed' : 'Generated')) : 'None' }}</td><td>{{ $p['attendance'] }}</td><td>{{ $p['submissions'] }}</td></tr>
@endforeach
</tbody></table></div>
@if($canMerge)
<label class="report-confirm"><input type="checkbox" name="confirmed" value="1" required>I understand the profiles I don't keep will be removed, with their records moved to the one I keep. This cannot be undone.</label>
<div class="buttons"><button>Merge into the selected profile</button></div>
@endif
</form>
@if($canMerge)<form method="post" action="/reports/duplicates/dismiss" class="report-dismiss">@csrf<input type="hidden" name="signature" value="{{ $group['signature'] }}"><button class="secondary small">These are different people</button></form>@endif
</article>
@empty
<p class="report-empty">No profiles share a name. Nothing to reconcile.</p>
@endforelse
</section>
