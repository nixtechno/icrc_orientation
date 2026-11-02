{{-- Maintenance jobs administrators run here instead of on the server. Each card says what it will change before it runs. --}}
@php($t = $tools)
@if($t['venue'])<p class="notice">This is the venue copy. Attendance days, sign-ins and badges are changed on the live site and reach this venue on the next sync. Only database updates run here.</p>@endif

<section class="card settings-tool" aria-labelledby="tool-database">
<h2 id="tool-database">Database updates</h2>
@if($t['pending'])
<p>{{ count($t['pending']) }} {{ count($t['pending']) === 1 ? 'update is' : 'updates are' }} waiting. They add new features' storage and do not change existing records.</p>
<ul class="settings-tool-list">@foreach($t['pending'] as $name)<li><code>{{ $name }}</code></li>@endforeach</ul>
<form method="post" action="/settings/tools/database">@csrf<button>Apply database updates</button></form>
@else<p class="muted">The database is up to date.</p>@endif
</section>

<section class="card settings-tool" aria-labelledby="tool-days">
<h2 id="tool-days">Take attendance all day</h2>
<p class="muted">Every programme day records every scan, from 00:00 to 23:59, with no late time. Cancelled days are left alone.</p>
@if($t['notAllDay'])<p>{{ $t['notAllDay'] }} programme {{ $t['notAllDay'] === 1 ? 'day is' : 'days are' }} not set up this way yet.</p>
@unless($t['venue'])<form method="post" action="/settings/tools/attendance-days">@csrf<button>Take attendance all day on every programme day</button></form>@endunless
@else<p class="muted">Every programme day already takes attendance all day.</p>@endif
</section>

<section class="card settings-tool" aria-labelledby="tool-signins">
<h2 id="tool-signins">Use badge print times as sign-ins</h2>
<p class="muted">For a day when staff collected their badge on arrival: the badge print time becomes their sign-in. Staff with a printed badge but no scan get a record. Anyone who signed in earlier keeps their own time.</p>
@if($t['signinDay'])
<form method="get" action="/settings" class="settings-tool-row"><input type="hidden" name="tab" value="tools"><label>Programme day<select name="signin_date" onchange="this.form.submit()">@foreach($t['days'] as $day)<option value="{{ $day->date->toDateString() }}" @selected($day->is($t['signinDay']))>{{ $day->date->format('D j M') }}</option>@endforeach</select></label><noscript><button class="secondary small">Show</button></noscript></form>
@php($s = $t['signins'])
@if($s['updated'] + $s['created'])
<p>On {{ $t['signinDay']->date->format('l j F') }}: {{ $s['updated'] }} {{ $s['updated'] === 1 ? 'record gets' : 'records get' }} a sign-in from the badge print time and {{ $s['created'] }} {{ $s['created'] === 1 ? 'record is' : 'records are' }} added. {{ $s['unchanged'] }} already {{ $s['unchanged'] === 1 ? 'has' : 'have' }} an earlier sign-in.</p>
@unless($t['venue'])<form method="post" action="/settings/tools/signins">@csrf<input type="hidden" name="date" value="{{ $t['signinDay']->date->toDateString() }}"><button>Use badge print times as sign-ins for {{ $t['signinDay']->date->format('j M') }}</button></form>@endunless
@else<p class="muted">Nothing to change on {{ $t['signinDay']->date->format('l j F') }}: no staff badges printed that day are missing a sign-in.</p>@endif
@endif
</section>

<section class="card settings-tool" aria-labelledby="tool-badges">
<h2 id="tool-badges">Mark printed badges as collected</h2>
<p class="muted">For badges handed out as they were printed. Each printed badge is marked collected at its print time. Badges printed at the self-service print station are marked collected automatically.</p>
@if($t['uncollected'])<p>{{ $t['uncollected'] }} printed {{ $t['uncollected'] === 1 ? 'badge is' : 'badges are' }} not marked collected.</p>
@unless($t['venue'])<form method="post" action="/settings/tools/badges-collected">@csrf<button>Mark {{ $t['uncollected'] }} printed {{ $t['uncollected'] === 1 ? 'badge' : 'badges' }} as collected</button></form>@endunless
@else<p class="muted">Every printed badge is marked collected.</p>@endif
</section>
