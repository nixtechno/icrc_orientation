<section class="card attendance-trend" aria-labelledby="attendance-trend-title">
<div class="dashboard-card-heading"><div><span class="eyebrow">COHORT ENGAGEMENT</span><h2 id="attendance-trend-title">Staff attendance trend</h2></div><span class="badge">Last 10 programme days</span></div>
<p class="muted">Recorded attendance through today, {{ now($p->timezone)->format('j F Y') }}. Late arrivals are shown separately.</p>
@if(count($attendanceTrend))
@php($chartMax=max(1,collect($attendanceTrend)->max(fn($day)=>$day['present']+$day['late'])))
<div class="attendance-legend"><span><i></i>Present</span><span><i class="late"></i>Late</span></div>
<div class="attendance-plot" role="img" aria-label="Staff attendance by programme day. Exact values are available in the table below.">
@foreach($attendanceTrend as $day)<div class="attendance-column"><span class="attendance-count">{{ $day['present']+$day['late'] }}</span><div class="attendance-track"><div class="attendance-stack" style="height:{{ 100*($day['present']+$day['late'])/$chartMax }}%"><div class="attendance-late" style="flex:{{ $day['late'] }}" title="{{ $day['late'] }} late"></div><div class="attendance-present" style="flex:{{ $day['present'] }}" title="{{ $day['present'] }} present"></div></div></div><span>{{ $day['date'] }}</span></div>@endforeach
</div>
<details><summary>View attendance figures</summary><div class="table-wrap"><table><thead><tr><th>Date</th><th>Present</th><th>Late</th><th>Total attended</th></tr></thead><tbody>@foreach($attendanceTrend as $day)<tr><td>{{ $day['full_date'] }}</td><td>{{ $day['present'] }}</td><td>{{ $day['late'] }}</td><td>{{ $day['present']+$day['late'] }}</td></tr>@endforeach</tbody></table></div></details>
@else<div class="empty">The attendance graph will appear once programme attendance days begin.</div>@endif
</section>
