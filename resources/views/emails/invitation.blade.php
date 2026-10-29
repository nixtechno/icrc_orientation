<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title>Your ICRC programme invitation</title>
<style>
@media only screen and (max-width:620px){.email-outer{padding:20px 10px!important}.email-content{padding:30px 24px!important}.email-title{font-size:32px!important}.email-brand{padding:28px 20px!important}.email-button{display:block!important;text-align:center!important}.email-footer{padding:23px 22px!important}}
</style>
</head>
<body style="margin:0;padding:0;background:#f0f3ee;color:#253c2d;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">
<div style="display:none;font-size:1px;line-height:1px;color:#f0f3ee;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">You are invited to the ICRC Orientation Programme. Activate your account and set your password within three days.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f0f3ee"><tr><td class="email-outer" align="center" style="padding:40px 18px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e0e7dc;border-radius:18px;overflow:hidden">
<tr><td style="height:5px;line-height:5px;font-size:1px;background:#c6a264">&nbsp;</td></tr>
<tr><td class="email-brand" align="center" style="padding:34px 35px 29px;border-bottom:1px solid #edf0e8">
<img src="{{ $logoSrc??$message->embed($logoPath) }}" width="78" height="72" alt="ICRC — The Presidency" style="display:block;width:78px;height:72px;object-fit:contain;margin:0 auto 17px;border:0">
<p style="margin:0 0 8px;color:#8c753f;font-size:9px;line-height:1.5;font-weight:700;letter-spacing:2.7px">THE PRESIDENCY</p>
<p style="margin:0;color:#23472e;font-size:14px;line-height:1.6;font-weight:600">Infrastructure Concession<br>Regulatory Commission</p>
</td></tr>
<tr><td class="email-content" style="padding:36px 44px 38px">
<p style="margin:0 0 17px;color:#8a764b;font-size:10px;font-weight:700;letter-spacing:2px;line-height:1.6">YOUR PROGRAMME INVITATION</p>
<p style="margin:0 0 13px;font-size:14px;line-height:1.8;color:#2c4332">Dear {{ $recipient->name }},</p>
<p style="margin:0 0 24px;font-size:14px;line-height:1.9;color:#647263">You have been invited to join the <strong style="color:#34563b;font-weight:600">ICRC Orientation Programme</strong> with <strong style="color:#34563b;font-weight:600">{{ $recipient->role }}</strong> access. Your account gives you access to the programme information and tools authorised for your role.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f7f1;border:1px solid #e5ebdd;border-radius:10px;margin:0 0 27px"><tr><td style="padding:19px 22px">
<p style="margin:0 0 7px;color:#819076;font-size:9px;font-weight:700;letter-spacing:1.8px;line-height:1.5">PROGRAMME DATES</p>
<p style="margin:0;color:#2d4c34;font-size:14px;font-weight:600;line-height:1.7">{{ $programme->starts_on->format('j F') }} – {{ $programme->ends_on->format('j F Y') }}</p>
<p style="margin:5px 0 0;color:#7c8975;font-size:11px;line-height:1.6">All programme times are shown in {{ $programme->timezone }}.</p>
</td></tr></table>
<p style="margin:0 0 23px;font-size:14px;line-height:1.9;color:#647263">To get started, activate your account, complete your profile and choose your own secure password.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;width:100%"><tr><td align="left">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $activationUrl }}" style="height:50px;v-text-anchor:middle;width:236px" arcsize="14%" stroke="f" fillcolor="#075332"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:13px;font-weight:bold">Activate your account</center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a class="email-button" href="{{ $activationUrl }}" style="display:inline-block;background:#075332;color:#ffffff;padding:17px 26px;border:1px solid #075332;border-radius:8px;font-size:13px;font-weight:600;line-height:1.2;text-decoration:none;text-align:center;mso-hide:all">Activate your account &nbsp; &rarr;</a><!--<![endif]-->
</td></tr></table>
<p style="margin:0 0 28px;color:#8b7953;font-size:11px;line-height:1.8">This personal invitation expires on <strong>{{ $expiresAt->copy()->timezone($programme->timezone)->format('j F Y \a\t H:i') }}</strong> ({{ $programme->timezone }}). It can be used once.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e7ecdf"><tr><td style="padding-top:23px">
<p style="margin:0 0 8px;font-size:11px;color:#82907b;line-height:1.8">If the button does not open, copy this link into your browser:</p>
<p style="margin:0;font-size:11px;line-height:1.9;word-break:break-all;overflow-wrap:anywhere"><a href="{{ $activationUrl }}" style="color:#40734b;text-decoration:underline;word-break:break-all;overflow-wrap:anywhere">{{ $activationUrl }}</a></p>
</td></tr></table>
<p style="margin:27px 0 0;color:#526d55;font-size:12px;line-height:1.8">We look forward to your participation.<br><strong style="color:#294d32">ICRC Programme Team</strong></p>
</td></tr>
<tr><td class="email-footer" style="padding:24px 44px;background:#f7f8f3;border-top:1px solid #e7ecdf">
<p style="margin:0 0 9px;font-size:10px;line-height:1.9;color:#84907d">This invitation was issued to {{ $recipient->email }} by authorised programme personnel. Keep your activation link private. If you were not expecting this invitation, contact your programme administrator.</p>
<p style="margin:0;font-size:9px;line-height:1.8;color:#9a8460;letter-spacing:.4px">&copy; {{ now()->year }} Infrastructure Concession Regulatory Commission</p>
</td></tr>
</table>
<p style="margin:23px 10px 0;color:#8d9a85;font-size:9px;line-height:1.6;letter-spacing:1.5px;text-align:center">KNOWLEDGE &nbsp; &middot; &nbsp; PARTNERSHIP &nbsp; &middot; &nbsp; PUBLIC SERVICE</p>
</td></tr></table>
</body></html>
