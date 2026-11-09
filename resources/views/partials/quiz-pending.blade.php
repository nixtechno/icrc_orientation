<link rel="stylesheet" href="{{ asset('quiz.css') }}?v=20261108b"><script src="{{ asset('quiz.js') }}?v=20261108b" defer></script>
{{-- Shown by quiz.js when this device holds answers the server has not confirmed yet. It keeps retrying, then shows the results. --}}
<section class="card quiz-pending" data-quiz-pending data-token="{{ csrf_token() }}" data-user="{{ auth()->id() }}" data-activity-url="{{ url('/activities/'.$activity->getRouteKey()) }}" hidden>
<h2>Submission pending</h2>
<p>Your assessment has ended, but the submission has not gone through yet because of a poor connection.</p>
<p class="quiz-pending-state" data-quiz-pending-message role="status" aria-live="polite">Trying to submit…</p>
<p class="muted">Keep this page open. If you have to close it, open this assessment again on the same phone or computer once you have a connection. Your results appear here as soon as the submission goes through.</p>
<button type="button" data-quiz-pending-retry>Try again now</button>
</section>
