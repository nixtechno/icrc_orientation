@if(config('services.recaptcha.enabled'))
<fieldset><legend>Security verification</legend>
<div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
@error('g-recaptcha-response')<p class="errors" role="alert">{{ $message }}</p>@enderror
</fieldset>
@endif
