@php($mine=$submissions->first())
@php($open=!($activity->due_at&&now()->gt($activity->due_at))&&!($activity->available_at&&now()->lt($activity->available_at)))
@php($locked=$mine&&($mine->score!==null||$mine->released_at))
@if($locked)<p class="notice">Your presentation has been reviewed, so it can no longer be changed.</p>
@elseif(!$open)<p class="notice">{{ $activity->available_at&&now()->lt($activity->available_at)?'Submissions open '.$activity->available_at->format('j F Y · H:i').'.':'The deadline has passed, so submissions are closed.' }}</p>
@else<script src="{{ asset('submission.js') }}?v=20261101a" defer></script>
<form method="post" action="/activities/{{ $activity->getRouteKey() }}/presentation" enctype="multipart/form-data" class="presentation-submit" data-final-submission data-pending-label="Uploading your presentation…">@csrf
<h3>{{ $mine?'Replace your submission':'Submit your presentation' }}</h3>
@if($mine?->path)<p class="muted">Current file: <a href="/submissions/{{ $mine->getRouteKey() }}/download">{{ $mine->filename }}</a>. Choose a new file only if you want to replace it.</p>@endif
<label>PowerPoint file<input type="file" name="file" accept=".{{ implode(',.',\App\Http\Controllers\ActivityController::PRESENTATION_FILES) }}"><small class="muted">PowerPoint (.pptx, .ppt, .ppsx), OpenDocument (.odp) or PDF; up to 50 MB.</small></label>
<label>Presentation text<textarea name="body" rows="8" maxlength="50000" placeholder="If your presentation does not use PowerPoint, write it here. You can also add notes for your file.">{{ old('body',$mine?->body) }}</textarea></label>
<button>{{ $mine?'Save changes':'Submit presentation' }}</button><p class="muted" style="margin-top:12px">Upload a file, write in the text box, or both. You can replace your submission until the deadline or until it is reviewed.</p>
</form>
@endif
