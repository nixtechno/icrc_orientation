{{-- The programme report: a section per area with headline figures, charts drawn by reports.js, and a table of the same figures under each chart. --}}
@php
    $reg = $report['registration']; $att = $report['attendance']; $mat = $report['materials']; $ass = $report['assessments']; $bad = $report['badges'];
    $pct = fn ($part, $whole) => $whole ? (int) round($part / $whole * 100) : 0;
    $pairs = fn ($rows) => ['categories' => array_column($rows, 'label'), 'values' => array_column($rows, 'value')];
    $printed = $bad['stages'][1]['value']; $collected = $bad['stages'][2]['value'];
@endphp
<script src="{{ asset('vendor/apexcharts/apexcharts.min.js') }}?v=3.54.1" defer></script><script src="{{ asset('reports.js') }}?v=20261102b" defer></script>
<nav class="report-jump" aria-label="Report sections"><a href="#registration">Registration</a><a href="#attendance">Attendance</a><a href="#materials">Learning materials</a><a href="#assessments">Assessments</a><a href="#badges">Badges</a><a href="#duplicates">Duplicate profiles @if($duplicates->count())<span>{{ $duplicates->count() }}</span>@endif</a></nav>

<section class="card report-section" id="registration" aria-labelledby="registration-title">
<h2 id="registration-title">Registration</h2>
<div class="report-tiles">
<div class="report-tile"><span>Enrolled staff</span><strong>{{ $reg['staff'] }}</strong></div>
<div class="report-tile"><span>Activated accounts</span><strong>{{ $reg['activated'] }}</strong><small>{{ $pct($reg['activated'], $reg['staff']) }}% of staff</small></div>
<div class="report-tile"><span>Awaiting activation</span><strong>{{ $reg['awaiting'] }}</strong></div>
<div class="report-tile"><span>Facilitators</span><strong>{{ $reg['facilitators'] }}</strong></div>
</div>
@if($reg['staff'])
<div class="report-meter" role="img" aria-label="{{ $reg['activated'] }} of {{ $reg['staff'] }} staff have activated their account">
<div class="report-meter-bar"><span style="width:{{ $pct($reg['activated'], $reg['staff']) }}%"></span></div>
<ul class="report-legend"><li><i class="is-primary"></i>Activated {{ $reg['activated'] }}</li><li><i class="is-rest"></i>Awaiting activation {{ $reg['awaiting'] }}</li></ul>
</div>
@else<p class="report-empty">No staff are enrolled yet.</p>@endif
</section>

<section class="card report-section" id="attendance" aria-labelledby="attendance-title">
<h2 id="attendance-title">Attendance</h2>
<div class="report-tiles">
<div class="report-tile"><span>Average daily attendance</span><strong>{{ $att['average'] === null ? '—' : $att['average'].'%' }}</strong></div>
<div class="report-tile"><span>Signed in today</span><strong>{{ $att['today']['signed_in'] ?? '—' }}</strong></div>
<div class="report-tile"><span>Signed out today</span><strong>{{ $att['today']['signed_out'] ?? '—' }}</strong></div>
<div class="report-tile"><span>Days counted</span><strong>{{ count($att['days']) }}</strong></div>
</div>
@if($att['days'])
<h3>Share of staff who attended each day (scanned in or out)</h3>
<div class="report-chart" data-chart="{{ json_encode(['type' => 'bar', 'name' => 'Attended', 'unit' => '%', 'max' => 100, 'categories' => array_column($att['days'], 'label'), 'values' => array_map(fn ($d) => $d['rate'] ?? 0, $att['days']), 'notes' => array_map(fn ($d) => $d['attended'].' of '.$d['expected'].' staff · '.$d['signed_in'].' in, '.$d['signed_out'].' out', $att['days'])]) }}"></div>
<details class="report-table"><summary>Show the figures</summary><table><thead><tr><th>Day</th><th>Expected</th><th>Attended</th><th>Signed in</th><th>Signed out</th><th>Attendance</th></tr></thead><tbody>@foreach($att['days'] as $d)<tr><td>{{ $d['label'] }}</td><td>{{ $d['expected'] }}</td><td>{{ $d['attended'] }}</td><td>{{ $d['signed_in'] }}</td><td>{{ $d['signed_out'] }}</td><td>{{ $d['rate'] === null ? '—' : $d['rate'].'%' }}</td></tr>@endforeach</tbody></table></details>
@else<p class="report-empty">No attendance days have taken place yet.</p>@endif
</section>

<section class="card report-section" id="materials" aria-labelledby="materials-title">
<h2 id="materials-title">Learning materials</h2>
<div class="report-tiles">
<div class="report-tile"><span>Published</span><strong>{{ $mat['published'] }}</strong></div>
<div class="report-tile"><span>Drafts</span><strong>{{ $mat['total'] - $mat['published'] }}</strong></div>
</div>
@if($mat['published'])
<div class="report-pair">
<div><h3>By category</h3><div class="report-chart" data-chart="{{ json_encode(['type' => 'bar', 'horizontal' => true, 'name' => 'Materials', ...$pairs($mat['categories'])]) }}"></div></div>
<div><h3>By programme week</h3><div class="report-chart" data-chart="{{ json_encode(['type' => 'bar', 'name' => 'Materials', ...$pairs($mat['weeks'])]) }}"></div></div>
</div>
<details class="report-table"><summary>Show the figures</summary><table><thead><tr><th>Category</th><th>Materials</th></tr></thead><tbody>@foreach($mat['categories'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach</tbody></table><table><thead><tr><th>Week</th><th>Materials</th></tr></thead><tbody>@foreach($mat['weeks'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach</tbody></table></details>
@else<p class="report-empty">No learning materials have been published yet.</p>@endif
</section>

<section class="card report-section" id="assessments" aria-labelledby="assessments-title">
<h2 id="assessments-title">Assessments, assignments and presentations</h2>
<div class="report-tiles">
<div class="report-tile"><span>Published</span><strong>{{ $ass['published'] }}</strong></div>
<div class="report-tile"><span>Submitted by assigned staff</span><strong>{{ $ass['rate'] === null ? '—' : $ass['rate'].'%' }}</strong></div>
<div class="report-tile"><span>Average assessment score</span><strong>{{ $ass['average'] === null ? '—' : $ass['average'].'%' }}</strong><small>Released results only</small></div>
</div>
@if($ass['rows'])
<h3>Submissions against assigned staff</h3>
<div class="report-chart" data-chart="{{ json_encode(['type' => 'bar', 'horizontal' => true, 'stacked' => true, 'categories' => array_column($ass['rows'], 'title'), 'series' => [['name' => 'Submitted', 'values' => array_column($ass['rows'], 'submitted')], ['name' => 'Not yet submitted', 'values' => array_column($ass['rows'], 'outstanding')]]]) }}"></div>
@php($scored = array_values(array_filter($ass['rows'], fn ($row) => $row['average'] !== null)))
@if($scored)<h3>Average score per assessment</h3>
<div class="report-chart" data-chart="{{ json_encode(['type' => 'bar', 'horizontal' => true, 'name' => 'Average score', 'unit' => '%', 'max' => 100, 'categories' => array_column($scored, 'title'), 'values' => array_column($scored, 'average')]) }}"></div>@endif
<details class="report-table"><summary>Show the figures</summary><table><thead><tr><th>Title</th><th>Type</th><th>Assigned</th><th>Submitted</th><th>Results released</th><th>Average score</th></tr></thead><tbody>@foreach($ass['rows'] as $row)<tr><td>{{ $row['title'] }}</td><td>{{ $row['kind'] }}</td><td>{{ $row['assigned'] }}</td><td>{{ $row['submitted'] }}</td><td>{{ $row['released'] }}</td><td>{{ $row['average'] === null ? '—' : $row['average'].'%' }}</td></tr>@endforeach</tbody></table></details>
@else<p class="report-empty">Nothing has been published yet.</p>@endif
</section>

<section class="card report-section" id="badges" aria-labelledby="badges-title">
<h2 id="badges-title">Badges</h2>
<div class="report-tiles">
<div class="report-tile"><span>People enrolled</span><strong>{{ $bad['people'] }}</strong></div>
<div class="report-tile"><span>Without a badge</span><strong>{{ $bad['without'] }}</strong></div>
<div class="report-tile"><span>Printed</span><strong>{{ $printed }}</strong><small>{{ $pct($printed, $bad['stages'][0]['value']) }}% of badges</small></div>
<div class="report-tile"><span>Collected</span><strong>{{ $collected }}</strong><small>{{ $pct($collected, $bad['stages'][0]['value']) }}% of badges</small></div>
</div>
@if($bad['stages'][0]['value'])
<h3>From generated to collected</h3>
<div class="report-chart" data-chart="{{ json_encode(['type' => 'bar', 'horizontal' => true, 'name' => 'Badges', ...$pairs($bad['stages'])]) }}"></div>
<details class="report-table"><summary>Show the figures</summary><table><thead><tr><th>Stage</th><th>Badges</th></tr></thead><tbody>@foreach($bad['stages'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach</tbody></table><table><thead><tr><th>Badge type</th><th>Badges</th></tr></thead><tbody>@foreach($bad['categories'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach</tbody></table></details>
@else<p class="report-empty">No badges have been generated yet.</p>@endif
</section>

@include('partials.report-duplicates')
