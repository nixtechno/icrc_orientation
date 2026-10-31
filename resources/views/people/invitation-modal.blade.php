@php($isAdmin = auth()->user()->role === 'admin')
<script type="application/json" data-invite-config>@json([...$invitationEmail, 'csrf' => csrf_token(), 'admin' => $isAdmin])</script>
<dialog class="invite-modal" data-invite-modal aria-labelledby="invite-modal-title">
<div class="invite-shell">
<header class="invite-head">
<div><span class="eyebrow">ACCOUNT ACTIVATION EMAIL</span><h2 id="invite-modal-title" data-invite-title>Send invitations</h2><p class="muted" data-invite-summary></p></div>
<button type="button" class="invite-close" data-invite-close aria-label="Close">&times;</button>
</header>
<div class="invite-views" role="tablist" aria-label="Email view"><button type="button" role="tab" aria-selected="true" data-invite-view="compose">Edit email</button><button type="button" role="tab" aria-selected="false" data-invite-view="preview">Preview</button></div>
<div class="invite-body" data-invite-body data-view="compose">
<section class="invite-compose" aria-label="Recipients and email content">
<fieldset class="invite-recipients" data-invite-mode="new">
<legend>Recipient</legend>
<div class="invite-new-fields"><label>Account type<select data-new="role">@foreach($isAdmin?['staff','facilitator']:['staff'] as $role)<option value="{{ $role }}">{{ ucfirst($role) }}</option>@endforeach</select></label><label>Full name *<input data-new="name" autocomplete="off" maxlength="255" aria-required="true"></label><label>Email *<input type="email" data-new="email" autocomplete="off" maxlength="255" aria-required="true"></label></div>
<p class="invite-hint">A new inactive account is created. The recipient completes their profile and creates their password; the account type cannot be changed through the invitation.</p>
</fieldset>
<fieldset class="invite-recipients" data-invite-mode="existing" hidden>
<legend>Recipients <span class="invite-count" data-invite-recipient-count></span></legend>
<ul class="invite-chips" data-invite-recipients></ul>
<p class="invite-hint">Sending a new invitation cancels any earlier unused link for that person. Activated accounts are skipped.</p>
</fieldset>
<label>Subject<input data-field="subject" maxlength="200" required></label>
<div class="invite-message-label"><label for="invite-body-input">Message</label><span class="invite-hint">Formatting: <code>**bold**</code> &middot; <code>## Heading</code> &middot; <code>- list item</code> &middot; <code>1. step</code></span></div>
<div class="invite-placeholders" role="group" aria-label="Insert personal details"><span>Insert:</span>@foreach($invitationEmail['placeholders'] as $key => $label)<button type="button" data-invite-insert="{{ '{'.$key.'}' }}" title="{{ $label }}">{{ $label }}</button>@endforeach</div>
<textarea id="invite-body-input" data-field="body" rows="18" maxlength="20000" required spellcheck="true"></textarea>
<label>Button text<input data-field="button" maxlength="60" required></label>
<p class="invite-hint">The button, expiry notice and a backup link are always added to the end of the email.</p>
<div class="invite-template-actions"><button type="button" class="small secondary" data-invite-reset>Reset to standard email</button>@if($isAdmin)<button type="button" class="small secondary" data-invite-save-default>Save as default</button>@endif<span class="invite-template-status" role="status" data-invite-template-status></span></div>
</section>
<section class="invite-preview" aria-label="Email preview">
<div class="invite-preview-head"><div><span class="invite-preview-label">Subject</span><strong data-invite-preview-subject></strong></div><span class="invite-preview-status" role="status" data-invite-preview-status></span></div>
<iframe title="Email preview" sandbox="allow-same-origin" data-invite-frame></iframe>
</section>
</div>
<div class="invite-errors" role="alert" data-invite-errors hidden></div>
<div class="invite-progress" data-invite-progress hidden><div class="invite-progress-top"><strong data-invite-progress-text></strong><span data-invite-progress-tally></span></div><progress max="1" value="0" data-invite-progress-bar></progress><ul class="invite-results" data-invite-results></ul></div>
<footer class="invite-foot"><p class="invite-hint" data-invite-foot-note>@if(config('mail.default')==='log')Local mail is written to the application log until SMTP is configured.@else Each recipient receives a personal link that expires after three days.@endif</p><div class="buttons"><button type="button" class="secondary" data-invite-close>Cancel</button><button type="button" data-invite-send>Send invitation</button></div></footer>
</div>
</dialog>
