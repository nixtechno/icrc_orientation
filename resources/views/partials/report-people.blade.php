{{-- The people behind a figure, so the count can be checked name by name. --}}
@if($people)
<details class="report-table"><summary>{{ $title }} ({{ count($people) }})</summary><table><thead><tr><th>Name</th>@if($type ?? false)<th>Type</th>@endif<th>Details</th></tr></thead><tbody>@foreach($people as $person)<tr><td>{{ $person['name'] }}</td>@if($type ?? false)<td>{{ ucfirst($person['type']) }}</td>@endif<td>{{ $person['detail'] ?: '—' }}</td></tr>@endforeach</tbody></table></details>
@endif
