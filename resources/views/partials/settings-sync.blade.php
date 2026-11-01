{{-- Settings → Venue sync. Venue servers show their connection, sync log and unplaced records; the live site shows uploads received. --}}
@php
    $s = $sync['status'];
    $when = fn ($iso) => $iso ? \Carbon\Carbon::parse($iso)->timezone('Africa/Lagos')->format('j M, H:i:s') : '—';
    $venue = $s['mode'] === 'venue';
@endphp
@if($venue)
<section class="card sync-summary" data-attendance-sync>
    <div class="sync-summary-head"><div><span class="eyebrow">THIS COPY IS A VENUE SERVER</span><h2>Connection to the live site</h2></div><form method="post" action="/attendance/sync" data-sync-now data-sync-reload>@csrf<button class="button secondary" type="submit">Sync now</button></form></div>
    <p role="status" aria-live="polite" data-sync-text class="muted">Checking sync…</p>
    @if(! ($s['upload'] ?? true))<p class="sync-note"><strong>Uploads are switched off.</strong> This server downloads from the live site, but nothing scanned here is sent to it. Set <code>ATTENDANCE_SYNC_UPLOAD=true</code> in <code>.env</code> to start sending scans.</p>@endif
    <dl class="sync-facts">
        <div><dt>Live site</dt><dd>{{ config('attendance_sync.url') }}</dd></div>
        <div><dt>Connection</dt><dd>{{ $s['online'] === true ? 'Online' : ($s['online'] === false ? 'Offline' : 'Not synced yet') }}</dd></div>
        <div><dt>Last successful sync</dt><dd>{{ $when($s['last_synced_at']) }}</dd></div>
        <div><dt>Last check</dt><dd>{{ $when($s['checked_at']) }}</dd></div>
        <div><dt>Uploads</dt><dd>{{ ($s['upload'] ?? true) ? 'On' : 'Off (pull only)' }}</dd></div>
        <div><dt>Scans waiting to upload</dt><dd>{{ $s['waiting'] }}</dd></div>
        <div><dt>Records with problems</dt><dd>{{ $s['problems'] }}</dd></div>
        <div><dt>Clock difference</dt><dd>{{ $s['clock_offset'] === null ? '—' : (abs($s['clock_offset']) < 60 ? abs($s['clock_offset']).' s' : round(abs($s['clock_offset']) / 60).' min — correct this computer’s clock') }}</dd></div>
    </dl>
    @if($s['online'] === false && $s['error'])<p class="sync-note sync-note-error">{{ $s['error'] }}</p>@endif
</section>
@else
<section class="card sync-summary">
    <span class="eyebrow">THIS COPY IS THE LIVE SITE</span><h2>Venue uploads</h2>
    @if($sync['configured'])
        <p>Ready to accept attendance from venue servers that use the same sync token.</p>
    @else
        <p class="sync-note"><strong>Sync is not set up.</strong> Add an <code>ATTENDANCE_SYNC_TOKEN</code> of at least 32 characters to <code>.env</code> and run <code>php artisan config:cache</code>. Venue servers must use the same token.</p>
    @endif
    <dl class="sync-facts">
        <div><dt>Last venue upload</dt><dd>{{ $when($s['received']['at'] ?? null) }}{{ isset($s['received']['origin']) ? ' from '.$s['received']['origin'] : '' }}</dd></div>
        @forelse($sync['origins'] as $origin => $count)
            <div><dt>Attendance recorded {{ $origin === 'online' ? 'on this site' : 'at '.$origin }}</dt><dd>{{ $count }}</dd></div>
        @empty
            <div><dt>Attendance records</dt><dd>0</dd></div>
        @endforelse
    </dl>
</section>
@endif

<section class="card">
    <h2>{{ $venue ? 'Sync log' : 'Uploads received' }}</h2>
    <p class="muted">The most recent {{ $venue ? 'sync runs' : 'venue uploads' }}, newest first. Runs with nothing new are combined into one line.</p>
    @if($sync['history'])
    <div class="table-wrap"><table class="sync-log"><thead><tr>
        @if($venue)<th>Time</th><th>Result</th><th>Uploaded</th><th>Downloaded</th><th>Notes</th>
        @else<th>Time</th><th>From</th><th>Records</th><th>Problems</th><th>Security log entries</th>@endif
    </tr></thead><tbody>
    @foreach($sync['history'] as $entry)
        <tr>
            <td>{{ $when($entry['at']) }}@if(($entry['repeats'] ?? 1) > 1)<br><small class="muted">×{{ $entry['repeats'] }} since {{ $when($entry['since']) }}</small>@endif</td>
            @if($venue)
                <td><span class="sync-result {{ $entry['online'] ? 'is-ok' : 'is-off' }}">{{ $entry['online'] ? 'Synced' : 'Offline' }}</span></td>
                <td>{{ $entry['upload'] ? ($entry['uploaded'] ?? '—') : 'Off' }}</td>
                <td>{{ $entry['downloaded'] }}</td>
                <td>{{ $entry['error'] ?? ($entry['unplaced'] ? $entry['unplaced'].' live record(s) not placed yet; will retry' : '') }}</td>
            @else
                <td>{{ $entry['origin'] }}</td><td>{{ $entry['records'] }}</td><td>{{ $entry['problems'] }}</td><td>{{ $entry['logs'] }}</td>
            @endif
        </tr>
    @endforeach
    </tbody></table></div>
    @else
    <p>{{ $venue ? 'No sync has run yet. Use Sync now, or keep php artisan schedule:work running.' : 'No venue uploads have been received yet.' }}</p>
    @endif
</section>

@if($sync['problems']->isNotEmpty())
<section class="card">
    <h2>Records with problems</h2>
    <p class="muted">These scans were not accepted by the live site. They are retried on every sync; fix the cause on the live site, usually a person who is missing there.</p>
    <div class="table-wrap"><table><thead><tr><th>Person</th><th>Day</th><th>Signed in</th><th>Signed out</th><th>Reason</th></tr></thead><tbody>
    @foreach($sync['problems'] as $record)
        <tr><td>{{ $record->staff_name }}</td><td>{{ $record->day?->date->format('j M') }}</td><td>{{ $record->checked_in_at?->timezone('Africa/Lagos')->format('H:i') ?? '—' }}</td><td>{{ $record->checked_out_at?->timezone('Africa/Lagos')->format('H:i') ?? '—' }}</td><td>{{ $record->sync_error }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endif
<p class="muted">Setting up a venue server is described in docs/venue-sync.md.</p>
<script src="{{ asset('attendance-sync.js') }}?v=20261101c" defer></script>
