{{-- The people behind a figure in the PDF report, so the count can be checked name by name. --}}
@if($people)
<h3>{{ $title }} <span class="muted">({{ count($people) }})</span></h3>
<table class="figures"><thead><tr><th>Name</th>@if($type ?? false)<th>Type</th>@endif<th>Details</th></tr></thead><tbody>
@foreach($people as $person)<tr><td>{{ $person['name'] }}</td>@if($type ?? false)<td>{{ ucfirst($person['type']) }}</td>@endif<td>{{ $person['detail'] ?: '—' }}</td></tr>@endforeach
</tbody></table>
@endif
