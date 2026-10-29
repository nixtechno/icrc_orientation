@php
 $profile=$person?->profile??[];
 $isFacilitator=($selectedRole??$person?->role??request('role'))==='facilitator';
 $emailLocked=($activation??false)||!($official??false)||($person?->hasLockedInvitationEmail()??false);
 $contactRecord=$isFacilitator&&$person?->exists?\App\Models\Facilitator::where('user_id',$person->id)->first():null;
@endphp
<script src="{{ asset('profile-fields.js') }}?v=20261029a" defer></script>
<div data-profile-fields data-profile-role="{{ $isFacilitator?'facilitator':'staff' }}">
<fieldset><legend>Personal &amp; contact details</legend><div class="form-grid">
@foreach(['first_name'=>'First name','middle_name'=>'Middle name (optional)','surname'=>'Surname','preferred_name'=>'Preferred name (optional)'] as $key=>$label)<label>{{ $label }}{{ in_array($key,['first_name','surname'])?' *':'' }}<input name="{{ $key }}" value="{{ old($key,$profile[$key]??'') }}" @required(in_array($key,['first_name','surname'])) maxlength="100"></label>@endforeach
<label>Email *<input type="email" name="email" value="{{ $emailLocked?$person?->email:old('email',$person?->email) }}" required @readonly($emailLocked)></label><label>Phone{{ (($activation??false)||!($official??false))?' *':' (optional until activation)' }}<input name="phone" value="{{ old('phone',$person?->phone) }}" @required(($activation??false)||!($official??false)) maxlength="40"></label><div class="full form-grid" data-profile-role-section="facilitator" @if(!$isFacilitator) hidden @endif>
<label>Organization<input name="organisation" maxlength="255" value="{{ old('organisation',$profile['organisation']??$contactRecord?->organisation) }}" @disabled(!$isFacilitator)></label>
<label>Department<input name="department" maxlength="255" value="{{ old('department',$person?->department??$contactRecord?->department) }}" @disabled(!$isFacilitator)></label>
<label>Title<input name="designation" maxlength="255" value="{{ old('designation',$person?->designation??$contactRecord?->job_title) }}" @disabled(!$isFacilitator)></label>
<label>Alternative phone number<input type="tel" name="alternative_phone" maxlength="40" value="{{ old('alternative_phone',$profile['alternative_phone']??$contactRecord?->alternative_phone) }}" @disabled(!$isFacilitator)></label>
</div><label class="full">Profile photograph (JPG / PNG, maximum 2 MB)<input type="file" name="photo" accept=".jpg,.jpeg,.png"></label></div></fieldset>
@if(!$isFacilitator||isset($selectedRole))<fieldset data-profile-role-section="staff" @if($isFacilitator) hidden disabled @endif><legend>Employment details</legend><div class="form-grid">@foreach(['staff_number'=>'Staff / employee number (if assigned)','department'=>'Department / unit','designation'=>'Job title / designation','group_name'=>'Programme group (if assigned)'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$person?->$key) }}" @readonly(!($official??false)||(($activation??false)&&$person?->$key))></label>@endforeach<label>Resumption date (optional)<input name="resumption_date" type="date" value="{{ old('resumption_date',$profile['resumption_date']??'') }}" @readonly(!($official??false))></label><label>Office / duty location (optional)<input name="duty_location" value="{{ old('duty_location',$profile['duty_location']??'') }}" @readonly(!($official??false))></label></div></fieldset>
<fieldset data-profile-role-section="staff" @if($isFacilitator) hidden disabled @endif><legend>Emergency contact (optional unless configured as required)</legend><div class="form-grid">@foreach(['emergency_name'=>'Contact name','emergency_relationship'=>'Relationship','emergency_phone'=>'Contact phone'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$profile[$key]??'') }}"></label>@endforeach</div></fieldset>
@if(!$isFacilitator&&count(\App\Services\Access::programme()->settings['required_profile_fields']??[]))<div class="notice">Additional required fields at activation: {{ implode(', ',array_map(fn($x)=>str_replace('_',' ',$x),\App\Services\Access::programme()->settings['required_profile_fields'])) }}.</div>@endif

@endif
</div>
