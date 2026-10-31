<section class="badge-sheet" aria-label="{{ $item['badge']->category }} badge for {{ $item['snapshot']['name'] }}">
@include('badges.face',['reverse'=>false])
@if($settings['layout']==='folded')@include('badges.face',['reverse'=>true])<div class="badge-fold-guide" aria-hidden="true"></div>@endif
</section>
