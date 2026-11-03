{{-- One programme day's training feedback per page: the averages, then everyone who responded with their ratings and suggestions. --}}
@php
    $averages = collect(\App\Models\TrainingFeedback::STEPS)->map(fn ($step, $key) => round((float) $feedback->avg($key.'_rating'), 1));
    $zone = \App\Services\Access::programme()->timezone;
    $index = $day ? $days->search(fn ($d) => $d->is($day)) : false;
    // Days are newest first, so the previous page is the later day.
    [$newer, $older] = $index === false ? [null, null] : [$days->get($index - 1), $days->get($index + 1)];
@endphp
<link rel="stylesheet" href="{{ asset('training-feedback.css') }}?v=20261103h">
<section class="card feedback-summary">
@if($days->isEmpty())<div class="empty">No feedback yet. It opens for staff at {{ $time }} on each programme day.</div>@else
<nav class="feedback-summary-days" aria-label="Programme days">@foreach($days as $d)<a href="/training-feedback?day={{ $d->id }}" @if($d->is($day)) aria-current="page" @endif>{{ $d->date->format('D j M') }}</a>@endforeach</nav>
<div class="feedback-summary-head"><div><h2>{{ $day->date->format('l j F Y') }}</h2>
<p class="muted">{{ $feedback->count() }} {{ $feedback->count() === 1 ? 'response' : 'responses' }}@if($staff) from {{ $staff }} staff @endif · ratings out of 5</p></div>
<div class="feedback-summary-pager">@if($older)<a class="button secondary small" href="/training-feedback?day={{ $older->id }}">&larr; {{ $older->date->format('D j M') }}</a>@endif @if($newer)<a class="button secondary small" href="/training-feedback?day={{ $newer->id }}">{{ $newer->date->format('D j M') }} &rarr;</a>@endif</div></div>
<div class="feedback-summary-grid">@foreach(\App\Models\TrainingFeedback::STEPS as $key => $step)<div><span>{{ $step['title'] }}</span><strong>{{ number_format($averages[$key], 1) }}</strong><div class="feedback-summary-bar"><i style="width:{{ $averages[$key] * 20 }}%"></i></div></div>@endforeach</div>
<div class="table-wrap"><table class="feedback-table"><thead><tr><th>Staff</th>@foreach(\App\Models\TrainingFeedback::STEPS as $step)<th class="feedback-rating-col">{{ $step['title'] }}</th>@endforeach<th>Suggestions</th><th>Sent</th></tr></thead><tbody>
@foreach($feedback as $f)<tr><td>{{ $f->user?->name ?? 'Deleted account' }}@if($f->user)<div class="meta">{{ collect([$f->user->staff_number, $f->user->department])->filter()->join(' · ') }}</div>@endif</td>
@foreach(\App\Models\TrainingFeedback::STEPS as $key => $step)<td class="feedback-rating-col"><span class="feedback-rating is-{{ $f->{$key.'_rating'} }}" title="{{ \App\Models\TrainingFeedback::SCALE[$f->{$key.'_rating'}] ?? '' }}">{{ $f->{$key.'_rating'} }}</span></td>@endforeach
<td class="feedback-suggestion">@if(filled($f->comment))<span class="preline">{{ $f->comment }}</span>@else<span class="muted">—</span>@endif</td><td class="meta">{{ $f->created_at->timezone($zone)->format('H:i') }}</td></tr>@endforeach
</tbody></table></div>
@endif
</section>
