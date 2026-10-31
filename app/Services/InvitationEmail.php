<?php

namespace App\Services;

use App\Models\Programme;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Editable invitation email content: a subject, a Markdown body and the
 * activation button label. The branded frame, the activation button, the
 * expiry notice and the fallback link are always added by the template.
 */
class InvitationEmail
{
    public const PLACEHOLDERS = [
        'first_name' => 'First or preferred name',
        'name' => 'Full name',
        'email' => 'Email address',
        'role' => 'Account type',
        'programme_dates' => 'Programme dates',
        'timezone' => 'Programme timezone',
        'expiry' => 'Invitation expiry',
    ];

    public const DEFAULT_SUBJECT = 'Welcome to the ICRC Orientation Programme | Create your password';

    public const DEFAULT_BUTTON = 'Create your password & sign in';

    public const DEFAULT_BODY = <<<'MD'
Dear {name},

Welcome to the **ICRC Orientation Programme for 2026 Recruited Staff**, running from **{programme_dates}**. An account has been created for you with **{role}** access. This email explains what the programme portal offers and how to get started.

## About the programme portal

The portal is your single home for the orientation. It brings together the timetable, daily attendance, learning materials, assessments and programme updates, so you always know where to be and what is expected of you. All programme times are shown in {timezone}.

## What you can do in the portal

- **Programme timetable**: follow each week and day of the programme, with session times, topics and facilitators.
- **Daily attendance**: check in and check out once each programme day using the signed daily QR code at the venue. Your attendance record is always visible to you.
- **Assessments and assignments**: take quizzes and submit assignments and presentations before their deadlines. Scores and feedback appear once they are released.
- **Resources**: open the presentations, handbooks and materials published for each session.
- **Announcements**: receive important updates from the programme team.
- **Session Q&A**: ask questions about sessions. Facilitators answer, and published answers are shared with participants.
- **Your profile**: keep your contact details up to date and add a photo for your programme badge.

## Getting started

1. Select the button below to open your personal activation page.
2. Confirm your details and complete any missing profile information.
3. Read and acknowledge the privacy notice.
4. Create a secure password of at least 8 characters.
5. Sign in with your email address and your new password.

Your activation link is personal to you, so please do not forward this email. If you need help at any point, contact the programme team.
MD;

    public static function defaults(): array
    {
        return ['subject' => self::DEFAULT_SUBJECT, 'body' => self::DEFAULT_BODY, 'button' => self::DEFAULT_BUTTON];
    }

    /** The saved default content for a programme, or the standard content. */
    public static function content(Programme $programme): array
    {
        return [...self::defaults(), ...array_intersect_key($programme->settings['invitation_email'] ?? [], self::defaults())];
    }

    public static function save(Programme $programme, array $content): void
    {
        $programme->update(['settings' => [...($programme->settings ?? []), 'invitation_email' => array_intersect_key($content, self::defaults())]]);
    }

    public static function rules(): array
    {
        $known = function (string $attribute, mixed $value, \Closure $fail) {
            preg_match_all('/\{([a-z_]+)\}/', (string) $value, $m);
            $unknown = array_diff(array_unique($m[1]), array_keys(self::PLACEHOLDERS));
            if ($unknown) {
                $fail('Unknown placeholder: {'.implode('}, {', $unknown).'}.');
            }
        };

        return [
            'subject' => ['required', 'string', 'max:200', $known],
            'body' => ['required', 'string', 'max:20000', $known],
            'button' => ['required', 'string', 'max:60'],
        ];
    }

    public static function variables(User $recipient, Programme $programme, CarbonInterface $expiresAt): array
    {
        $profile = $recipient->profile ?? [];
        $first = ($profile['preferred_name'] ?? null) ?: ($profile['first_name'] ?? null) ?: Str::before(trim((string) $recipient->name), ' ');

        return [
            '{first_name}' => $first ?: (string) $recipient->name,
            '{name}' => (string) $recipient->name,
            '{email}' => (string) $recipient->email,
            '{role}' => ucfirst((string) $recipient->role),
            '{programme_dates}' => $programme->starts_on->format('j F').' – '.$programme->ends_on->format('j F Y'),
            '{timezone}' => $programme->timezone,
            '{expiry}' => $expiresAt->copy()->timezone($programme->timezone)->format('j F Y \a\t H:i'),
        ];
    }

    public static function subject(string $subject, array $vars): string
    {
        return trim(preg_replace('/\s+/', ' ', strtr($subject, $vars)));
    }

    public static function text(string $body, array $vars): string
    {
        return strtr($body, $vars);
    }

    /** Markdown body to email-safe HTML with inline styles; raw HTML and unsafe links are removed. */
    public static function html(string $body, array $vars): string
    {
        $html = Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = strtr($html, [
            '<p>' => '<p style="margin:0 0 18px;font-size:14px;line-height:1.9;color:#4f604f">',
            '<h1>' => '<h2 style="margin:30px 0 12px;font-size:17px;line-height:1.45;color:#23472e;font-weight:700">',
            '</h1>' => '</h2>',
            '<h2>' => '<h2 style="margin:30px 0 12px;font-size:17px;line-height:1.45;color:#23472e;font-weight:700">',
            '<h3>' => '<h3 style="margin:24px 0 10px;font-size:15px;line-height:1.5;color:#23472e;font-weight:700">',
            '<ul>' => '<ul style="margin:0 0 20px;padding:0 0 0 20px;color:#4f604f">',
            '<ol>' => '<ol style="margin:0 0 20px;padding:0 0 0 22px;color:#4f604f">',
            '<li>' => '<li style="margin:0 0 9px;font-size:14px;line-height:1.8">',
            '<strong>' => '<strong style="color:#2d4c34;font-weight:600">',
            '<a href=' => '<a style="color:#40734b;text-decoration:underline" href=',
            '<blockquote>' => '<blockquote style="margin:0 0 20px;padding:14px 18px;background:#f5f7f1;border-left:3px solid #c6a264;border-radius:6px">',
            '<hr />' => '<hr style="border:0;border-top:1px solid #e7ecdf;margin:26px 0">',
        ]);
        // Tight list items are rendered without <p>; loose ones keep the paragraph spacing tidy.
        $html = preg_replace('/(<li[^>]*>)<p style="[^"]*">(.*?)<\/p>/s', '$1$2', $html);

        return strtr($html, array_map(fn ($v) => e($v), $vars));
    }
}
