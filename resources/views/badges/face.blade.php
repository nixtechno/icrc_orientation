<div class="badge-face {{ ($reverse??false)?'badge-back':'' }}">
<img class="badge-background" src="{{ $background }}" alt="">
<div class="badge-brand">@if($logo)<img class="badge-logo" src="{{ $logo }}" alt="ICRC">@endif<div class="badge-brand-text">Infrastructure Concession<br>Regulatory Commission<small>THE PRESIDENCY</small></div></div>
<div class="badge-programme">{{ $settings['label'] }}</div>
<div class="badge-theme">Building Competence, Inspiring Excellence,<br>Strengthening Institutional Capacity.</div>
<div class="badge-identity"><div class="badge-name" data-badge-text="name" style="font-size:{{ $item['fit']['name']*$fontScale }}mm"><span data-badge-field="first_name">{{ $item['snapshot']['first_name'] }}</span><span data-badge-field="last_name">{{ $item['snapshot']['last_name'] }}</span></div>
<div class="badge-designation" data-badge-text="title" data-badge-field="designation" style="font-size:{{ $item['fit']['title']*$fontScale }}mm">{{ $item['snapshot']['designation'] }}</div>
<div class="badge-organisation" data-badge-text="organisation" data-badge-field="organisation" style="font-size:{{ $item['fit']['organisation']*$fontScale }}mm">{{ $item['snapshot']['organisation'] }}</div>
</div><div class="badge-motto">{{ $settings['footer'] }}</div><div class="badge-rule"></div>
<div class="badge-event"><strong>{{ $p->starts_on->format('j M') }} &ndash; {{ $p->ends_on->format('j M Y') }}</strong><small>{{ $p->settings['venue']??'Venue to be confirmed' }}</small></div>
<div class="badge-role" style="background:{{ $item['colour'] }}">{{ $item['badge']->category }}</div>
<div class="badge-qr"><img src="{{ $item['qr'] }}" alt="Badge verification QR code"></div>
<div class="badge-producer">PRODUCERS @if($producer)<img src="{{ $producer }}" alt="Brevity Anderson">@endif</div>

</div>
