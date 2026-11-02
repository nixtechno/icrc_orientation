@php
    // The programme report as an A4 document. Dompdf has no flexbox or grid and runs no scripts,
    // so the layout is tables and the charts are bars drawn from the same figures as the report tab.
    $reg = $report['registration']; $att = $report['attendance']; $mat = $report['materials']; $ass = $report['assessments']; $bad = $report['badges'];
    $pct = fn ($part, $whole) => $whole ? (int) round($part / $whole * 100) : 0;
    $dash = fn ($value, $suffix = '') => $value === null ? '—' : $value.$suffix;
    $bars = function (array $rows, ?int $max = null, string $unit = '') {
        $top = $max ?? max(1, ...array_column($rows, 'value'));
        return collect($rows)->map(fn ($row) => [...$row, 'width' => $top ? min(100, round($row['value'] / $top * 100, 1)) : 0, 'shown' => $row['value'].$unit]);
    };
    $printed = $bad['stages'][1]['value']; $badgeTotal = $bad['stages'][0]['value'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Programme report · {{ $p->title }}</title>
<style>
@page { margin: 22mm 16mm 20mm 16mm; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; line-height: 1.45; color: #1b2f28; }
h1, h2, h3, p { margin: 0; }

.running { position: fixed; left: 0; right: 0; font-size: 7pt; color: #6b7d74; }
.running.top { top: -14mm; border-bottom: 0.6pt solid #d6e0d9; padding-bottom: 2mm; }
.running.bottom { bottom: -12mm; border-top: 0.6pt solid #d6e0d9; padding-top: 2mm; }
.running table { width: 100%; border-collapse: collapse; }
.running td { padding: 0; }
.running .right { text-align: right; }
.page-number:after { content: "Page " counter(page); }

.cover { background: #0b4838; color: #fff; border-radius: 6pt; padding: 16pt 18pt; margin-bottom: 14pt; }
.cover table { width: 100%; border-collapse: collapse; }
.cover td { vertical-align: middle; padding: 0; }
.cover .logo { width: 62pt; }
.cover .logo img { width: 54pt; height: auto; background: #fff; border-radius: 4pt; padding: 3pt; }
.cover .eyebrow { font-size: 6.6pt; letter-spacing: 0.9pt; color: #d9c08b; font-weight: bold; }
.cover h1 { font-size: 20pt; line-height: 1.15; margin: 3pt 0 4pt; }
.cover .meta { font-size: 8pt; color: #d6e6dd; }
.cover .generated { text-align: right; font-size: 7.4pt; color: #d6e6dd; width: 120pt; }
.cover .generated strong { display: block; color: #fff; font-size: 9pt; }

.lead { font-size: 9pt; color: #44574e; margin-bottom: 10pt; }
.summary { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 5pt; margin: 0 -5pt 8pt; }
.summary td { width: 25%; border: 0.7pt solid #d6e0d9; border-radius: 4pt; padding: 7pt 8pt; vertical-align: top; background: #f7faf6; }
.summary .label { font-size: 6.8pt; color: #6b7d74; text-transform: uppercase; letter-spacing: 0.5pt; font-weight: bold; }
.summary .value { font-size: 17pt; font-weight: bold; color: #0b4838; line-height: 1.2; }
.summary .note { font-size: 6.8pt; color: #6b7d74; }

.section { margin-top: 14pt; }
.section-head { border-bottom: 1.4pt solid #0b4838; padding-bottom: 3pt; margin-bottom: 8pt; }
.section-head h2 { font-size: 12.5pt; color: #0b4838; }
.section-head .number { color: #bd9450; font-weight: bold; margin-right: 4pt; }
h3 { font-size: 9pt; margin: 10pt 0 4pt; color: #1b2f28; }
.muted { color: #6b7d74; }
.empty { border: 0.7pt dashed #cbd6ce; border-radius: 4pt; padding: 9pt; text-align: center; color: #6b7d74; }

.tiles { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 4pt; margin: 0 -4pt; }
.tiles td { border: 0.7pt solid #e0e7e2; border-radius: 3pt; padding: 5pt 7pt; vertical-align: top; }
.tiles .label { font-size: 6.6pt; color: #6b7d74; }
.tiles .value { font-size: 12.5pt; font-weight: bold; color: #1b2f28; }
.tiles .note { font-size: 6.6pt; color: #6b7d74; }

.meter { height: 7pt; background: #c5cfc9; border-radius: 4pt; margin: 8pt 0 3pt; }
.meter div { height: 7pt; background: #1f7a4d; border-radius: 4pt; }
.legend { font-size: 7pt; color: #44574e; }
.swatch { display: inline-block; width: 7pt; height: 7pt; border-radius: 2pt; vertical-align: middle; margin: 0 3pt 0 8pt; }
.swatch.first { margin-left: 0; }

.chart { width: 100%; border-collapse: collapse; }
.chart td { padding: 2.2pt 0; vertical-align: middle; }
.chart .label { width: 34%; padding-right: 6pt; font-size: 7.6pt; }
.chart .track { width: 54%; }
.chart .value { width: 12%; text-align: right; font-size: 7.6pt; font-weight: bold; padding-left: 6pt; }
.bar { height: 9pt; background: #1f7a4d; border-radius: 0 3pt 3pt 0; }
.rest { height: 9pt; background: #c5cfc9; }
.split { width: 100%; border-collapse: collapse; }
.split td { padding: 0; }

.figures { width: 100%; border-collapse: collapse; margin-top: 6pt; font-size: 7.6pt; }
.figures th { text-align: left; font-size: 6.8pt; color: #6b7d74; text-transform: uppercase; letter-spacing: 0.4pt; border-bottom: 0.9pt solid #b9c7bd; padding: 3pt 5pt; }
.figures td { border-bottom: 0.5pt solid #e0e7e2; padding: 3pt 5pt; }
.figures .num { text-align: right; }
.figures tr { page-break-inside: avoid; }
thead { display: table-header-group; }
.keep { page-break-inside: avoid; }
.break { page-break-before: always; }
</style>
</head>
<body>
<div class="running top"><table><tr><td>ICRC · {{ $p->title }}</td><td class="right">Programme report</td></tr></table></div>
<div class="running bottom"><table><tr><td>Generated {{ $generated->format('j F Y, H:i') }} ({{ $p->timezoneLabel() }} time) from live records</td><td class="right page-number"></td></tr></table></div>

<div class="cover">
<table><tr>
@if($logo)<td class="logo"><img src="{{ $logo }}" alt="ICRC"></td>@endif
<td>
<div class="eyebrow">INFRASTRUCTURE CONCESSION REGULATORY COMMISSION</div>
<h1>Programme report</h1>
<div class="meta">{{ $p->title }} · {{ $p->starts_on->format('j M') }} – {{ $p->ends_on->format('j M Y') }}@if(filled($p->settings['venue'] ?? null)) · {{ $p->settings['venue'] }}@endif</div>
</td>
<td class="generated"><strong>{{ $generated->format('j F Y') }}</strong>Generated at {{ $generated->format('H:i') }}</td>
</tr></table>
</div>

<p class="lead">Registration, attendance, learning materials, assessments and badges for the programme, taken from live records when this report was generated.</p>

<table class="summary">
<tr>
<td><div class="label">Enrolled staff</div><div class="value">{{ $reg['staff'] }}</div><div class="note">{{ $reg['facilitators'] }} facilitators</div></td>
<td><div class="label">Activated accounts</div><div class="value">{{ $pct($reg['activated'], $reg['staff']) }}%</div><div class="note">{{ $reg['activated'] }} of {{ $reg['staff'] }} staff</div></td>
<td><div class="label">Average attendance</div><div class="value">{{ $dash($att['average'], '%') }}</div><div class="note">{{ count($att['days']) }} {{ count($att['days']) === 1 ? 'day' : 'days' }} counted</div></td>
<td><div class="label">Badges printed</div><div class="value">{{ $printed }}</div><div class="note">{{ $bad['without'] }} people without a badge</div></td>
</tr>
<tr>
<td><div class="label">Published work</div><div class="value">{{ $ass['published'] }}</div><div class="note">assessments, assignments and presentations</div></td>
<td><div class="label">Submitted by assigned staff</div><div class="value">{{ $dash($ass['rate'], '%') }}</div><div class="note">across all published work</div></td>
<td><div class="label">Average assessment score</div><div class="value">{{ $dash($ass['average'], '%') }}</div><div class="note">released results only</div></td>
<td><div class="label">Learning materials</div><div class="value">{{ $mat['published'] }}</div><div class="note">published · {{ $mat['total'] - $mat['published'] }} drafts</div></td>
</tr>
</table>

{{-- 1 · Registration --}}
<div class="section">
<div class="section-head"><h2><span class="number">1</span>Registration</h2></div>
<table class="tiles"><tr>
<td><div class="label">Enrolled staff</div><div class="value">{{ $reg['staff'] }}</div></td>
<td><div class="label">Activated accounts</div><div class="value">{{ $reg['activated'] }}</div></td>
<td><div class="label">Awaiting activation</div><div class="value">{{ $reg['awaiting'] }}</div></td>
<td><div class="label">Facilitators</div><div class="value">{{ $reg['facilitators'] }}</div></td>
</tr></table>
@if($reg['staff'])
<div class="meter"><div style="width: {{ $pct($reg['activated'], $reg['staff']) }}%"></div></div>
<div class="legend"><span class="swatch first" style="background:#1f7a4d"></span>Activated {{ $reg['activated'] }} ({{ $pct($reg['activated'], $reg['staff']) }}%)<span class="swatch" style="background:#c5cfc9"></span>Awaiting activation {{ $reg['awaiting'] }}</div>
@else<p class="empty">No staff are enrolled yet.</p>@endif
</div>

{{-- 2 · Attendance --}}
<div class="section">
<div class="section-head"><h2><span class="number">2</span>Attendance</h2></div>
<table class="tiles"><tr>
<td><div class="label">Average daily attendance</div><div class="value">{{ $dash($att['average'], '%') }}</div></td>
<td><div class="label">Attended today</div><div class="value">{{ $att['today']['attended'] ?? '—' }}</div>@if($att['today'])<div class="note">of {{ $att['today']['expected'] }} staff</div>@endif</td>
<td><div class="label">Signed in today</div><div class="value">{{ $att['today']['signed_in'] ?? '—' }}</div></td>
<td><div class="label">Signed out today</div><div class="value">{{ $att['today']['signed_out'] ?? '—' }}</div></td>
</tr></table>
@if($att['days'])
<h3>Share of staff who attended each day <span class="muted">(scanned in or out)</span></h3>
<table class="chart">@foreach($att['days'] as $d)<tr class="keep"><td class="label">{{ $d['label'] }}</td><td class="track"><div class="bar" style="width: {{ min(100, (int) $d['rate']) }}%"></div></td><td class="value">{{ $dash($d['rate'], '%') }}</td></tr>@endforeach</table>
<table class="figures"><thead><tr><th>Day</th><th class="num">Expected</th><th class="num">Attended</th><th class="num">Signed in</th><th class="num">Signed out</th><th class="num">Attendance</th></tr></thead><tbody>
@foreach($att['days'] as $d)<tr><td>{{ $d['label'] }}</td><td class="num">{{ $d['expected'] }}</td><td class="num">{{ $d['attended'] }}</td><td class="num">{{ $d['signed_in'] }}</td><td class="num">{{ $d['signed_out'] }}</td><td class="num">{{ $dash($d['rate'], '%') }}</td></tr>@endforeach
</tbody></table>
@else<p class="empty">No attendance days have taken place yet.</p>@endif
</div>

{{-- 3 · Learning materials --}}
<div class="section keep">
<div class="section-head"><h2><span class="number">3</span>Learning materials</h2></div>
<table class="tiles"><tr>
<td><div class="label">Published</div><div class="value">{{ $mat['published'] }}</div></td>
<td><div class="label">Drafts</div><div class="value">{{ $mat['total'] - $mat['published'] }}</div></td>
<td style="border:0"></td><td style="border:0"></td>
</tr></table>
@if($mat['published'])
<h3>By category</h3>
<table class="chart">@foreach($bars($mat['categories']) as $row)<tr><td class="label">{{ $row['label'] }}</td><td class="track"><div class="bar" style="width: {{ $row['width'] }}%"></div></td><td class="value">{{ $row['shown'] }}</td></tr>@endforeach</table>
<h3>By programme week</h3>
<table class="chart">@foreach($bars($mat['weeks']) as $row)<tr><td class="label">{{ $row['label'] }}</td><td class="track"><div class="bar" style="width: {{ $row['width'] }}%"></div></td><td class="value">{{ $row['shown'] }}</td></tr>@endforeach</table>
@else<p class="empty">No learning materials have been published yet.</p>@endif
</div>

{{-- 4 · Assessments --}}
<div class="section">
<div class="section-head"><h2><span class="number">4</span>Assessments, assignments and presentations</h2></div>
<table class="tiles"><tr>
<td><div class="label">Published</div><div class="value">{{ $ass['published'] }}</div></td>
<td><div class="label">Submitted by assigned staff</div><div class="value">{{ $dash($ass['rate'], '%') }}</div></td>
<td><div class="label">Average assessment score</div><div class="value">{{ $dash($ass['average'], '%') }}</div><div class="note">released results only</div></td>
<td style="border:0"></td>
</tr></table>
@if($ass['rows'])
<h3>Submissions against assigned staff</h3>
<div class="legend"><span class="swatch first" style="background:#1f7a4d"></span>Submitted<span class="swatch" style="background:#c5cfc9"></span>Not yet submitted</div>
<table class="chart">@foreach($ass['rows'] as $row)<tr class="keep"><td class="label">{{ $row['title'] }}</td><td class="track">
@if($row['assigned'])<table class="split"><tr>@if($row['submitted'])<td style="width: {{ $pct($row['submitted'], $row['assigned']) }}%"><div class="bar" style="border-radius:0"></div></td>@endif @if($row['outstanding'])<td><div class="rest"></div></td>@endif</tr></table>@else<span class="muted">Nobody assigned</span>@endif
</td><td class="value">{{ $row['submitted'] }}/{{ $row['assigned'] }}</td></tr>@endforeach</table>
<table class="figures"><thead><tr><th>Title</th><th>Type</th><th class="num">Assigned</th><th class="num">Submitted</th><th class="num">Released</th><th class="num">Average score</th></tr></thead><tbody>
@foreach($ass['rows'] as $row)<tr><td>{{ $row['title'] }}</td><td>{{ $row['kind'] }}</td><td class="num">{{ $row['assigned'] }}</td><td class="num">{{ $row['submitted'] }}</td><td class="num">{{ $row['released'] }}</td><td class="num">{{ $dash($row['average'], '%') }}</td></tr>@endforeach
</tbody></table>
@else<p class="empty">Nothing has been published yet.</p>@endif
</div>

{{-- 5 · Badges --}}
<div class="section keep">
<div class="section-head"><h2><span class="number">5</span>Badges</h2></div>
<table class="tiles"><tr>
<td><div class="label">People enrolled</div><div class="value">{{ $bad['people'] }}</div></td>
<td><div class="label">Without a badge</div><div class="value">{{ $bad['without'] }}</div></td>
<td><div class="label">Printed</div><div class="value">{{ $printed }}</div><div class="note">{{ $pct($printed, $badgeTotal) }}% of badges</div></td>
<td><div class="label">Collected</div><div class="value">{{ $bad['stages'][2]['value'] }}</div><div class="note">{{ $pct($bad['stages'][2]['value'], $badgeTotal) }}% of badges</div></td>
</tr></table>
@if($badgeTotal)
<h3>From generated to collected</h3>
<table class="chart">@foreach($bars($bad['stages']) as $row)<tr><td class="label">{{ $row['label'] }}</td><td class="track"><div class="bar" style="width: {{ $row['width'] }}%"></div></td><td class="value">{{ $row['shown'] }}</td></tr>@endforeach</table>
@if($bad['categories'])<table class="figures"><thead><tr><th>Badge type</th><th class="num">Badges</th></tr></thead><tbody>@foreach($bad['categories'] as $row)<tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>@endforeach</tbody></table>@endif
@else<p class="empty">No badges have been generated yet.</p>@endif
</div>

{{-- 6 · Duplicates --}}
<div class="section keep">
<div class="section-head"><h2><span class="number">6</span>Possible duplicate profiles</h2></div>
@if($duplicates->isNotEmpty())
<p class="muted">Profiles sharing a first name and surname, waiting to be reconciled in Reports.</p>
<table class="figures"><thead><tr><th>Name</th><th class="num">Profiles</th></tr></thead><tbody>@foreach($duplicates as $group)<tr><td>{{ $group['name'] }}</td><td class="num">{{ $group['count'] }}</td></tr>@endforeach</tbody></table>
@else<p class="empty">No profiles share a name. Nothing to reconcile.</p>@endif
</div>
</body>
</html>
