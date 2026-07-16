<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PreferenceMailNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $actionText = null,
        public readonly ?string $actionUrl = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello '.$notifiable->name.',');

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        if ($this->actionText !== null && $this->actionUrl !== null) {
            $message->action($this->actionText, $this->actionUrl);
        }

        return $message->line('You can change these emails from your HRMS account settings.');
    }
}
