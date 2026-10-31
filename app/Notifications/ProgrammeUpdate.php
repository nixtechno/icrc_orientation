<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A programme email: announcements, newly assigned work and learning materials staff were told about. */
class ProgrammeUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body, public string $url, public string $action = 'Open the portal') {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title)->greeting('Hello '.(preg_split('/\s+/', trim($notifiable->name))[0] ?? '').',');
        foreach (preg_split('/\R{2,}/', trim($this->body)) as $paragraph) {
            $message->line($paragraph);
        }

        return $message->action($this->action, $this->url);
    }
}
