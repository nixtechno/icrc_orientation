<form method="post" action="/badges/{{ $badge->getRouteKey() }}/collection" class="badge-collection-form">@csrf
<p class="badge-code-label">Collection code<strong>{{ $badge->collection_code }}</strong></p>
@foreach(['badge_printed'=>['Badge printed','printed_at'],'badge_collected'=>['Badge collected','collected_at'],'materials_collected'=>['Training materials collected','materials_collected_at']] as $key=>[$label,$field])
<label class="badge-check"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$badge->$field!==null))><span>{{ $label }}@if($badge->$field)<small>{{ $badge->$field->timezone('Africa/Lagos')->format('j M Y · H:i') }}</small>@endif</span></label>
@endforeach
<button>Confirm collection</button>
</form>
