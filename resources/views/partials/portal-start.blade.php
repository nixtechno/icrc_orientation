@php
 $user=auth()->user();
 $links=[['/dashboard','Dashboard','grid'],['/timetable','Programme timetable','calendar'],['/attendance','Daily attendance','check'],['/activities?kind=quiz','Assessments','file'],['/activities?kind=assignment','Assignments','file'],['/activities?kind=presentation','Presentations','screen'],['/resources','Learning materials','book'],['/announcements','Announcements','bell'],['/qa','Session Q&A','chat']];
 $management=[];
 foreach(['staff'=>['/manage/staff','Staff management','people'],'facilitators'=>['/facilitators','Facilitators','people'],'reports'=>['/reports','Reports & exports','chart']] as $permission=>$item){if($user->canManage($permission))$management[]=$item;}
 if($user->role==='admin')$management=array_merge($management,[['/settings','Programme settings','settings'],['/settings/registration','Registration policy','file'],['/badges','Badges & printing','badge'],['/audit','Audit trail','chart']]);
 $initials=collect(explode(' ',$user->name))->filter()->take(2)->map(fn($part)=>mb_substr($part,0,1))->implode('');
@endphp
<div class="portal-frame">
<aside class="portal-sidebar" id="portal-navigation" aria-label="Portal navigation">
 <a class="portal-brand" href="{{ url('/dashboard') }}"><img src="{{ $logoUrl }}" alt="ICRC" width="48" height="48"><span><strong>ICRC</strong><small>Orientation portal</small></span></a>
 <div class="portal-sidebar-scroll">
 @foreach(['YOUR WORKSPACE'=>$links,'MANAGEMENT'=>$management,'ACCOUNT'=>[['/profile','My profile','people']]] as $group=>$items)
 @if(count($items))<div class="portal-nav-label">{{ $group }}</div><nav aria-label="{{ $group }}">
 @foreach($items as [$href,$label,$icon])
 @php($active=request()->getPathInfo()===parse_url($href,PHP_URL_PATH) && (!str_contains($href,'?') || request('kind')===match($label){'Assessments'=>'quiz','Assignments'=>'assignment','Presentations'=>'presentation',default=>null}))
 <a href="{{ url($href) }}" @class(['portal-nav-link','selected'=>$active]) @if($active) aria-current="page" @endif><span class="portal-icon" data-icon="{{ $icon }}" aria-hidden="true"></span><span>{{ $label }}</span>@if($active)<i aria-hidden="true"></i>@endif</a>
 @endforeach</nav>@endif
 @endforeach
 <a class="portal-nav-link" href="{{ url('/').'#contact' }}"><span class="portal-icon" data-icon="chat" aria-hidden="true"></span>Help & support</a>
 <form action="{{ url('/logout') }}" method="post" class="portal-signout">@csrf<button type="submit"><span class="portal-icon" data-icon="logout" aria-hidden="true"></span>Sign out</button></form>
 </div>
</aside>
<button class="portal-scrim" aria-label="Close navigation" tabindex="-1" hidden></button>
<div class="portal-main-column">
<header class="portal-topbar">
 <button class="portal-menu" type="button" aria-label="Open navigation" aria-controls="portal-navigation" aria-expanded="false"><span class="portal-icon" data-icon="menu" aria-hidden="true"></span></button>
 <form class="portal-search" action="{{ url('/timetable') }}" method="get" role="search"><span class="portal-icon" data-icon="search" aria-hidden="true"></span><input aria-label="Search programme timetable" name="q" placeholder="Search your programme…" autocomplete="off"><kbd>/</kbd></form>
 <div class="portal-top-actions"><a class="portal-notifications" href="{{ url('/dashboard').'#notifications' }}" aria-label="Notifications"><span class="portal-icon" data-icon="bell" aria-hidden="true"></span>@if($user->unreadNotifications()->exists())<i></i>@endif</a><details class="portal-account"><summary class="portal-user" aria-label="Account options" aria-controls="portal-account-options"><span class="portal-avatar">{{ mb_strtoupper($initials) }}</span><span class="portal-user-label"><strong>{{ $user->name }}</strong><small>{{ ucfirst($user->role) }}</small></span><svg class="account-chevron" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></summary><div class="portal-account-options" id="portal-account-options"><div class="portal-account-identity"><strong>{{ $user->name }}</strong><span>{{ $user->email }}</span><small>{{ ucfirst($user->role) }} account</small></div><nav aria-label="Account"><a href="{{ url('/profile') }}"><span class="portal-icon" data-icon="people" aria-hidden="true"></span>My profile</a><a href="{{ url('/profile').'#change-password' }}"><span class="portal-icon" data-icon="settings" aria-hidden="true"></span>Change password</a>@if($user->role==='admin')<a href="{{ url('/settings') }}"><span class="portal-icon" data-icon="settings" aria-hidden="true"></span>Programme settings</a>@endif<a href="{{ url('/').'#contact' }}"><span class="portal-icon" data-icon="chat" aria-hidden="true"></span>Help & support</a></nav><form action="{{ url('/logout') }}" method="post">@csrf<button type="submit"><span class="portal-icon" data-icon="logout" aria-hidden="true"></span>Sign out</button></form></div></details></div>
</header>
<div class="app-shell">
