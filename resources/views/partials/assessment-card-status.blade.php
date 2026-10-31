@php
    [$assessmentStatus, $assessmentTone] = match (true) {
        (bool) $a->staff_submitted => ['Submitted', ''],
        $a->due_at && now()->gt($a->due_at) => ['Closed', 'warning'],
        $a->available_at && now()->lt($a->available_at) => ['Upcoming', 'warning'],
        (bool) $a->staff_started => ['In progress', 'warning'],
        default => ['Not started', ''],
    };
@endphp
<span class="badge {{ $assessmentTone }}" @if($assessmentStatus === 'Not started') style="background:#edf0ef;color:#53635b" @endif aria-label="Assessment status: {{ $assessmentStatus }}">{{ $assessmentStatus }}</span>
