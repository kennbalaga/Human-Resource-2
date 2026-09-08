<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A security event, delivered to the person it happened to.
 *
 * Separate from PreferenceMailNotification for one reason: that one closes by
 * telling the reader they can turn these emails off, which is true of a
 * schedule change and false here. A two-factor reset or a lockout is the mail
 * somebody needs precisely on the day they have every other notification
 * silenced, so it is not offered as a preference and does not claim to be.
 */
class SecurityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subject,
        public readonly string $message,
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
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message);

        if ($this->actionText !== null && $this->actionUrl !== null) {
            $mail->action($this->actionText, $this->actionUrl);
        }

        return $mail
            ->line('If this was not you, contact your System Administrator immediately.')
            ->salutation('This is a security message from HRMS and cannot be switched off in your notification preferences.');
    }

    /**
     * Queued, so the try/catch at the call site only ever sees a dispatch
     * failure. A refused SMTP handshake lands here instead, and a security
     * email that never arrived is worth a line in the log.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('A queued security email failed to send.', [
            'subject' => $this->subject,
            'error' => $exception->getMessage(),
        ]);
    }
}
