@if($u->role==='admin')
@php
 $programmeId=$p->id;
 $allPeople=\App\Models\User::whereHas('programmes',fn($q)=>$q->where('programmes.id',$programmeId));
 $areas=[
 ['People & invitations','/manage/staff',(clone $allPeople)->count(),'All staff, supervisors and authorised accounts'],
 ['Facilitator profiles','/facilitators',\App\Models\Facilitator::where('programme_id',$programmeId)->count(),'Profiles, photographs and session assignments'],
 ['Assessments & work','/activities',\App\Models\Activity::where('programme_id',$programmeId)->count(),'Quizzes, assignments, presentations and reviews'],
 ['Session Q&A','/qa',\App\Models\SessionQuestion::whereHas('session.day.week',fn($q)=>$q->where('programme_id',$programmeId))->count(),'Questions, answers and moderation'],
 ['Badges & printing','/badges',\App\Models\Badge::where('programme_id',$programmeId)->count(),'Generate, regenerate and print participant badges'],
 ['Learning materials','/resources',\Illuminate\Support\Facades\DB::table('resources')->where('programme_id',$programmeId)->count(),'Published and unpublished resources']
 ];
@endphp
<section class="portal-overview"><h2>Application oversight</h2><div class="grid">@foreach($areas as [$label,$href,$count,$description])<article class="card"><span class="muted">{{ $label }}</span><strong class="stat">{{ $count }}</strong><p class="muted">{{ $description }}</p><a href="{{ $href }}">Manage {{ strtolower($label) }} →</a></article>@endforeach</div><div class="buttons" style="margin:25px 0"><a href="/timetable">Full programme timetable</a><a href="/announcements">Announcements</a><a href="/reports">Reports</a><a href="/settings/registration">Registration policy</a><a href="/settings">System settings</a><a href="/audit">Audit logs</a></div></section>
@endif
