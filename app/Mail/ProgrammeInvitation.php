<?php

namespace App\Mail;

use App\Models\Programme;
use App\Models\User;
use App\Services\{BadgeService, InvitationEmail};
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Attachment, Content};
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

class ProgrammeInvitation extends Mailable
{
    public string $logoPath;

    /** A public logo URL used instead of an embedded image, for in-browser previews. */
    public ?string $logoSrc = null;

    public string $subjectLine;

    public string $bodyHtml;

    public string $bodyText;

    public string $buttonLabel;

    /** The recipient's badge print code, shown as a QR in the email and attached as an image. */
    public string $printCode;

    /** A data URI used instead of an embedded image, for in-browser previews. */
    public ?string $printQrSrc = null;

    public function __construct(
        public User $recipient,
        public Programme $programme,
        public string $activationUrl,
        public CarbonInterface $expiresAt,
        ?array $content = null,
    ) {
        $logo = $programme->settings['logo'] ?? null;
        $this->logoPath = $logo && Storage::disk('public')->exists($logo)
            ? Storage::disk('public')->path($logo)
            : public_path('brand/ICRC_Logo.png');
        $content ??= InvitationEmail::content($programme);
        $vars = InvitationEmail::variables($recipient, $programme, $expiresAt);
        $this->subjectLine = InvitationEmail::subject($content['subject'], $vars);
        $this->bodyHtml = InvitationEmail::html($content['body'], $vars);
        $this->bodyText = InvitationEmail::text($content['body'], $vars);
        $this->buttonLabel = trim($content['button']);
        // Previews use an unsaved sample person; only real recipients are issued a code.
        $this->printCode = $recipient->exists ? $recipient->printCode() : str_repeat('0', 16);
    }

    public function printQrPng(): string
    {
        return BadgeService::qrPng($this->printCode);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitation', text: 'emails.invitation-text');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->printQrPng(), 'ICRC-SCODEX-QR-code.png')->withMime('image/png')];
    }
}
