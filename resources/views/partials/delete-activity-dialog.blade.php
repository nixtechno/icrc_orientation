{{-- One confirmation dialog for every delete button on the page; activity-delete.js fills it in. --}}
<dialog class="confirm-dialog" data-delete-dialog aria-labelledby="delete-activity-title">
<form method="post" action="">@csrf @method('DELETE')<input type="hidden" name="confirmed" value="1">
<h2 id="delete-activity-title" data-delete-heading>Delete this activity?</h2>
<p data-delete-message></p>
<p class="muted">This removes it from everyone it was assigned to. It cannot be undone.</p>
<div class="buttons"><button type="button" class="secondary" data-delete-cancel>Cancel</button><button class="danger" data-delete-confirm>Delete</button></div>
</form></dialog>
