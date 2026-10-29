THE PRESIDENCY
Infrastructure Concession Regulatory Commission

YOUR PROGRAMME INVITATION

Dear {{ $recipient->name }},

You have been invited to join the ICRC Orientation Programme with {{ $recipient->role }} access.

Programme dates: {{ $programme->starts_on->format('j F') }} – {{ $programme->ends_on->format('j F Y') }}.
Programme timezone: {{ $programme->timezone }}.

Activate your account, complete your profile and choose your own secure password:
{{ $activationUrl }}

This invitation expires on {{ $expiresAt->copy()->timezone($programme->timezone)->format('j F Y \a\t H:i') }} ({{ $programme->timezone }}). It can be used once.

This invitation was issued to {{ $recipient->email }} by authorised programme personnel. Keep your activation link private. If you were not expecting it, contact your programme administrator.

We look forward to your participation.
ICRC Programme Team

Knowledge. Partnership. Public service.
