{{-- Submitted attempts grouped by device, with how many of each submitted automatically, so a device type or brand that keeps dropping out of fullscreen stands out. --}}
@php
    $finished = $submissions->whereNotNull('submitted_at');
    $groups = $finished->groupBy(function ($s) {
        if (! $s->device) return 'Device not recorded';
        $d = \App\Services\DeviceLabel::describe($s->device);
        return collect([$d['type'], $d['brand'] === 'Other' ? '' : $d['brand'], $d['system']])->filter()->join(' · ');
    })->map(fn ($group, $label) => ['label' => $label, 'total' => $group->count(), 'auto' => $group->filter(fn ($s) => $s->submission_reason && $s->submission_reason !== 'manual')->count(),
        'models' => $group->filter(fn ($s) => $s->submission_reason && $s->submission_reason !== 'manual')->map(fn ($s) => \App\Services\DeviceLabel::describe($s->device)['model'])->filter()->countBy()->sortDesc()])
    ->sortByDesc(fn ($g) => [$g['auto'], $g['total']]);
    $autoTotal = $groups->sum('auto');
@endphp
@if($autoTotal)
<section class="card"><h2>Automatic submissions by device</h2>
<p class="muted">{{ $autoTotal }} of {{ $finished->count() }} submitted {{ $finished->count() === 1 ? 'attempt was' : 'attempts were' }} submitted automatically (left fullscreen, closed the page or ran out of time). A device type with a high share points to a device problem rather than the person.</p>
<div class="table-wrap"><table><thead><tr><th>Device</th><th>Attempts</th><th>Automatic</th><th>Share</th><th>Models that submitted automatically</th></tr></thead><tbody>
@foreach($groups as $g)<tr><td>{{ $g['label'] }}</td><td>{{ $g['total'] }}</td><td>{{ $g['auto'] }}</td><td>@if($g['auto'])<span class="badge {{ $g['auto'] / $g['total'] >= .5 ? 'warning' : '' }}">{{ round($g['auto'] / $g['total'] * 100) }}%</span>@else 0% @endif</td><td>{{ $g['models']->map(fn ($n, $m) => $m.($n > 1 ? ' ×'.$n : ''))->join(', ') ?: '—' }}</td></tr>@endforeach
</tbody></table></div>
@if($groups->has('Device not recorded'))<p class="muted">Attempts started before devices were recorded show as not recorded.</p>@endif
</section>
@endif
