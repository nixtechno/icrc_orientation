<?php

namespace App\Mail;

use App\Models\Programme;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

class ProgrammeInvitation extends Mailable
{
    public string $logoPath;

    public function __construct(
        public User $recipient,
        public Programme $programme,
        public string $activationUrl,
        public CarbonInterface $expiresAt,
    ) {
        $logo = $programme->settings['logo'] ?? null;
        $this->logoPath = $logo && Storage::disk('public')->exists($logo)
            ? Storage::disk('public')->path($logo)
            : public_path('brand/ICRC_Logo.png');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your invitation | ICRC Orientation Programme');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitation', text: 'emails.invitation-text');
    }
}
