@if(auth()->user()->role==='admin')
@php($profileBadge=\App\Models\Badge::where('programme_id',\App\Services\Access::programme()->id)->where('user_id',$person->id)->first())
<section class="card"><h2>Profile badge</h2>@if($profileBadge)<div class="buttons"><a class="button" href="/badges/{{ $profileBadge->getRouteKey() }}/edit">Edit / print badge</a><a href="/badges/{{ $profileBadge->getRouteKey() }}/verify">Verify / collection</a></div>@else<form method="post" action="/badges/generate">@csrf<input type="hidden" name="subjects[]" value="u:{{ $person->id }}"><button>Create &amp; print badge</button></form>@endif</section>
@endif
