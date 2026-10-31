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
        'start_date' => 'Programme start date',
        'timezone' => 'Programme timezone',
        'expiry' => 'Invitation expiry',
    ];

    public const DEFAULT_SUBJECT = 'Welcome to the ICRC SCODEX Training Programme | Activate your account';

    public const DEFAULT_BUTTON = 'Create your password & sign in';

    public const DEFAULT_BODY = <<<'MD'
🎓 **WELCOME TO THE ICRC SCODEX TRAINING PROGRAMME!**

Dear {name},

**Congratulations and Welcome Aboard!** 🎉

If you are receiving this message, it means you have been **successfully enrolled in the ICRC SCODEX Training Programme**, scheduled to commence on **{start_date}**.

We are delighted to welcome you to this exciting and impactful learning experience!

📌 **TRAINING COMMENCEMENT DETAILS**

🗓️ **Start Date:** {start_date}

⏰ **Time:** 8:00 AM Prompt

📍 **Venue:** CBN, Central Area, Abuja

👔 **Dress Code:** Corporate Attire – Dress Smart and Professional

💻 **Laptop:** All participants are encouraged to come with their laptops and chargers.

🎒 **COLLECTION OF TRAINING BAGS & LEARNING MATERIALS**

Please come with your **unique QR Code**, which will be scanned at the venue for verification and collection of your training bag and learning materials.

Ensure your QR Code is readily accessible on your phone or as a printed copy to facilitate a smooth verification and collection process.

🔐 **IMPORTANT: COMPLETE YOUR ONBOARDING PROCESS**

Before arriving for the training, you are required to **activate your account and complete your onboarding process**.

🌐 **Access the SCODEX Training Portal:**
https://icrcscodex.bcommz.com

**Follow these steps:**

1. Select the **Create your password & sign in** button below.
2. Complete your boarding/onboarding process.
3. Create your personal login password.
4. Log in to your training dashboard.
5. Familiarise yourself with the portal and its features.

⚠️ **Please complete your portal activation and onboarding before the training commences on {start_date}.**

💻 **INTRODUCING YOUR DIGITAL LEARNING PORTAL**

The **SCODEX Training Portal** will serve as your central learning and engagement platform throughout the programme.

Through the portal, you will be able to:

✅ **Attendance:** Record and track your daily training attendance.

✅ **Learning Materials:** Access course documents, presentations and other training resources.

✅ **Assignments:** Receive, complete and submit your assignments online.

✅ **Assessments:** Participate in quizzes, tests and knowledge evaluations.

✅ **Presentations:** Access and submit presentation-related activities.

✅ **Learning Progress:** Monitor your training participation and progress.

We encourage all participants to explore the portal before the training begins to ensure a smooth and productive learning experience.

📢 **IMPORTANT REMINDERS**

✔ **Arrive at the venue by 8:00 AM prompt.**

✔ Dress smartly in corporate attire.

✔ Come with your laptop and charger.

✔ Complete your portal activation and onboarding before Monday.

✔ Keep your unique QR Code accessible for verification and collection of your training materials.

✔ Familiarise yourself with your SCODEX Training Dashboard.

✔ Be punctual, prepared and ready to participate actively.

🚀 **GET READY FOR AN EXCITING LEARNING EXPERIENCE!**

We look forward to welcoming you on **{start_date}, at CBN, Central Area, Abuja, by 8:00 AM.**

Get ready for an engaging, practical, interactive and rewarding learning experience designed to enhance your knowledge, strengthen your skills and support your professional development.

**Once again, congratulations and welcome to the ICRC SCODEX Training Programme!** 🎉🎓

Warm regards,

**SCODEX Training Coordination Team**

🌐 https://icrcscodex.bcommz.com
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
            '{start_date}' => $programme->starts_on->format('l, jS F Y'),
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
