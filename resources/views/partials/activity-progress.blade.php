{{-- Submitted, pending and reviewed staff for one activity, with a bar showing how many of those assigned have submitted. --}}
@php($submitted=(int)($counts->submitted??0))
@php($reviewed=(int)($counts->reviewed??0))
@php($pending=max(0,$assigned-$submitted))
@php($percent=$assigned?min(100,round($submitted/$assigned*100)):0)
<div class="activity-progress">
<dl class="activity-stats"><div><dt>Submitted</dt><dd>{{ $submitted }}</dd></div><div><dt>Pending</dt><dd>{{ $pending }}</dd></div><div><dt>Reviewed</dt><dd>{{ $reviewed }}</dd></div></dl>
<div class="activity-progress-bar" role="progressbar" aria-label="Submitted" aria-valuemin="0" aria-valuemax="{{ $assigned }}" aria-valuenow="{{ $submitted }}" aria-valuetext="{{ $submitted }} of {{ $assigned }} submitted"><span style="width:{{ $percent }}%"></span></div>
<p class="activity-progress-caption">{{ $submitted }} of {{ $assigned }} submitted &middot; {{ $percent }}%</p>
</div>
