{{-- The staff member's next step on an activity card, worded for its kind and where they are with it. --}}
@php
    $open = !($a->due_at && now()->gt($a->due_at)) && !($a->available_at && now()->lt($a->available_at));
    [$text, $primary] = match ($a->kind) {
        'presentation' => match (true) {
            (bool) $a->staff_released => ['View feedback', false],
            (bool) $a->staff_submitted => [$open ? 'View or update submission' : 'View submission', false],
            $open => ['Submit presentation', true],
            default => ['View presentation', false],
        },
        'quiz' => match (true) {
            (bool) $a->staff_released => ['View score', false],
            (bool) $a->staff_submitted => ['View assessment', false],
            $open && $a->staff_started => ['Continue assessment', true],
            $open => ['Start assessment', true],
            default => ['View assessment', false],
        },
        default => ['Open '.$a->label(), false],
    };
@endphp
@if($primary)<a class="button small activity-card-cta" href="/activities/{{ $a->getRouteKey() }}">{{ $text }}</a>@else<a href="/activities/{{ $a->getRouteKey() }}">{{ $text }} &rarr;</a>@endif
