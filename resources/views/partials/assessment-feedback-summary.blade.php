{{-- Averages and comments from staff feedback on this assessment; who gave each response is not shown. --}}
<link rel="stylesheet" href="{{ asset('assessment-feedback.css') }}?v=20261103b">
<section class="card feedback-summary"><h2>Staff feedback</h2>
@if($feedback->isEmpty())<div class="empty">No feedback yet. Staff give it straight after they submit.</div>@else
<p class="muted">{{ $feedback->count() }} {{ $feedback->count()===1?'response':'responses' }} · ratings out of 5</p>
<div class="feedback-summary-grid">@foreach(\App\Models\AssessmentFeedback::STEPS as $key=>$step)@php($average=round($feedback->avg($key.'_rating'),1))<div><span>{{ $step['title'] }}</span><strong>{{ number_format($average,1) }}</strong><div class="feedback-summary-bar"><i style="width:{{ $average*20 }}%"></i></div></div>@endforeach</div>
@php($comments=$feedback->pluck('comment')->filter()->values())
@if($comments->isNotEmpty())<details @if($comments->count()<=8) open @endif><summary>Comments ({{ $comments->count() }})</summary><ul class="feedback-comments">@foreach($comments as $comment)<li class="preline">{{ $comment }}</li>@endforeach</ul></details>@endif
@endif</section>
