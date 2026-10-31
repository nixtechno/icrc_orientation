<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Badge editor · {{ $badge->snapshot['name'] }}</title>
<link rel="stylesheet" href="{{ asset('badges.css') }}?v=20261030c"><script src="{{ asset('badge-editor.js') }}?v=20261030b" defer></script>
@include('badges.print-style',$data)
</head><body class="badge-workbench" data-badge-editor data-width-scale="{{ min($data['settings']['width_mm']/97,$data['settings']['height_mm']/138) }}">
<header class="badge-toolbar"><a class="badge-back" href="/badges">&larr; Badges &amp; printing</a><div><a href="{{ $type==='u'?'/manage/staff/'.$person->getRouteKey():'/facilitators' }}">Back to profile</a><a href="/badges/{{ $badge->getRouteKey() }}/verify">Verify / collection</a><a data-badge-pdf target="_blank" rel="noopener" href="{{ url('/badges/batch').'?'.http_build_query(['ids'=>[$badge->getRouteKey()],'pdf'=>1,...request()->only('name_size','title_size','organisation_size','layout')]) }}">Open PDF</a><button type="button" data-print-badge>Print badge</button></div></header>
<aside class="badge-editor-panel" id="badge-edit-panel" data-badge-panel hidden inert><button type="button" class="badge-panel-close" data-close-badge-editor aria-label="Close badge editor">&times;</button><h1>Edit badge</h1><p class="badge-help">Profile details are saved to the profile. Text sizing only affects this print view. Long text is automatically fitted.</p>
@if(session('success'))<p class="badge-message" role="status">{{ session('success') }}</p>@endif
@if($errors->any())<div class="badge-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@include('badges.collection-form')
<hr>
<form method="get" data-badge-size-form><h2>Print appearance</h2>
@foreach(['name'=>'Name','title'=>'Title','organisation'=>'Organization'] as $key=>$label)<label>{{ $label }} size (mm)<input type="number" name="{{ $key }}_size" value="{{ $data['sizes'][$key] }}" min="{{ $key==='name'?4:2 }}" max="{{ $key==='name'?12:6 }}" step="0.1" data-badge-size="{{ $key }}"></label>@endforeach
<label>Print layout<select name="layout"><option value="folded" @selected($data['settings']['layout']==='folded')>Folded front and back</option><option value="single" @selected($data['settings']['layout']==='single')>Single face</option></select></label><button class="secondary">Apply print layout</button>
</form><hr>
@php($snapshot=$data['items']->first()['snapshot'])
<form method="post" action="/badges/{{ $badge->getRouteKey() }}" data-badge-profile-form>@csrf<h2>Profile details</h2>
@foreach(['first_name'=>'First name','last_name'=>'Surname','designation'=>'Title / designation','organisation'=>'Organization'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$snapshot[$key]??'') }}" maxlength="{{ in_array($key,['first_name','last_name'])?100:255 }}" @required($key==='first_name') data-profile-field="{{ $key }}"></label>@endforeach
<label>Profile type<input value="{{ ucfirst($profile['category']) }}" readonly></label><p class="badge-help">The badge type follows the profile's assigned role.</p><button>Save profile &amp; badge</button></form>
</aside>
<main class="badge-stage"><p class="badge-unsaved" data-badge-unsaved hidden role="status">Save your profile changes before printing.</p>
@foreach($data['items'] as $item)@include('badges.sheet',$data)@endforeach
<nav class="badge-neighbours" aria-label="Browse badges">@if($previous)<a href="/badges/{{ $previous->getRouteKey() }}/edit">&larr; Previous badge</a>@endif @if($next)<a href="/badges/{{ $next->getRouteKey() }}/edit">Next badge &rarr;</a>@endif</nav>
</main></body></html>
