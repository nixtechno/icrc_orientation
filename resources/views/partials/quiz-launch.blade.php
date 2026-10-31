@php
    $quizAttempts = $activity->submissions()->where('user_id', auth()->id())->get(['submitted_at']);
    $quizLaunchUnavailable = null;
    if ($quizAttempts->contains(fn ($attempt) => (bool) $attempt->submitted_at)) {
        $quizLaunchUnavailable = 'Your assessment has been submitted. No attempts remaining.';
    } elseif ($activity->due_at && now()->gt($activity->due_at)) {
        $quizLaunchUnavailable = 'The assessment deadline has passed.';
    } elseif ($activity->available_at && now()->lt($activity->available_at)) {
        $quizLaunchUnavailable = 'This assessment is not available yet.';
    }
@endphp
@if($quizLaunchUnavailable)
<p class="notice" role="status"><strong>{{ $quizLaunchUnavailable }}</strong></p>
@else
<script src="{{ asset('quiz.js') }}?v=20261030c" defer></script>
<link rel="stylesheet" href="{{ asset('quiz.css') }}?v=20261030a">
<div data-quiz-entry data-start-url="/activities/{{ $activity->getRouteKey() }}/start">
@csrf
<div class="quiz-policy" role="note"><h3>IMPORTANT ASSESSMENT NOTICE</h3><p><strong>This assessment runs in fullscreen. Leaving or cancelling fullscreen before you click Submit final answers automatically submits your current answers, including unanswered questions.</strong></p><p><strong>Do not refresh, navigate away or close the assessment. Once submitted, this attempt cannot be resumed. Keep your connection active so your answers can be saved.</strong></p><label><input type="checkbox" data-quiz-ack required>I understand and accept the fullscreen submission rule.</label></div>
<button type="button" data-quiz-start>Start Assessment / Quiz</button><p data-quiz-launch-status class="muted" role="status"></p>
<noscript><p class="errors">JavaScript and a browser supporting fullscreen are required to start this assessment.</p></noscript>
<div class="quiz-stage" data-quiz-stage hidden aria-label="Fullscreen assessment"></div>
</div>
@endif
