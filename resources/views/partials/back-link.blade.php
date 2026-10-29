@php
 $backLink=null;
 if(request()->is('invitation/*','forgot-password','reset-password/*')) $backLink=['/login','Back to sign in'];
 elseif(auth()->check()&&!request()->is('/','dashboard','login')) {
   $backLink=match(true) {
     request()->is('manage/staff/*')=>['/manage/staff','Back to people'],
     request()->is('activities/*')=>['/activities','Back to assessments'],
     request()->is('submissions/*/complete')=>['/activities/'.$activity->getRouteKey(),'Back to assessment'],
     request()->is('manage/sessions/*')=>['/timetable','Back to timetable'],
     request()->is('qa/sessions/*')=>['/qa','Back to sessions'],
     request()->is('attendance/*')=>['/attendance','Back to attendance'],
     request()->is('badges/*')=>['/badges','Back to badges'],
     request()->is('settings/*')=>['/settings','Back to settings'],
     default=>['/dashboard','Back to dashboard'],
   };
 }
@endphp
@if($backLink)<nav class="page-back" aria-label="Back navigation"><a href="{{ $backLink[0] }}"><span aria-hidden="true">&larr;</span> {{ $backLink[1] }}</a></nav>@endif
