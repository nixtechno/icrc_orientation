<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProgrammeUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body, public string $url, public bool $email = false) {}

    public function via($notifiable): array
    {
        return $this->email ? ['database', 'mail'] : ['database'];
    }

    public function toArray($notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->body)->action('Open programme', $this->url);
    }
}
