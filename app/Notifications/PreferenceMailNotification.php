<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class PreferenceMailNotification extends Notification implements ShouldQueue
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

        // No "you can turn these off" line: the switches it used to point at
        // are gone, and notification email is now one System Administrator's
        // setting for everybody.
        return $message;
    }

    /**
     * Now that this notification is queued, PreferenceNotificationService's
     * try/catch around ->notify() only ever catches dispatch-time failures —
     * a queued job returns immediately, so a real delivery failure (bad SMTP
     * credentials, etc.) surfaces here instead. Without this, that failure
     * would go from "logged" to "silently sitting in failed_jobs."
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('A queued preference email failed to send.', [
            'subject' => $this->subject,
            'error' => $exception->getMessage(),
        ]);
    }
}
