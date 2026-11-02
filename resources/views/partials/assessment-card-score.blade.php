{{-- The staff member's result in the corner of an assessment card: the released score, or that marking is still under way. --}}
@if($a->staff_score!==null)
@php($cardTotal=(float)$a->total_points)
<div class="activity-card-score" aria-label="Your score: {{ +$a->staff_score }}@if($cardTotal) out of {{ +$cardTotal }}@endif"><strong>{{ +$a->staff_score }}@if($cardTotal)<small>/{{ +$cardTotal }}</small>@endif</strong>@if($cardTotal)<span>{{ round($a->staff_score/$cardTotal*100) }}%</span>@endif</div>
@elseif($a->staff_submitted)
<div class="activity-card-score"><span>Awaiting marking</span></div>
@endif
