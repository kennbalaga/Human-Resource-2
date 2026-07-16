<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class PreferenceNotificationService
{
    /** @var array<int, string> */
    private const ALLOWED_PREFERENCES = [
        'attendance_reminders',
        'schedule_updates',
        'leave_updates',
    ];

    public function send(User $user, string $preference, Notification $notification): bool
    {
        if (! in_array($preference, self::ALLOWED_PREFERENCES, true)) {
            throw new InvalidArgumentException("Unsupported notification preference [{$preference}].");
        }

        if (! $user->is_active || blank($user->email)) {
            return false;
        }

        $settings = $user->preference;

        if (! $settings->email_notifications || ! $settings->{$preference}) {
            return false;
        }

        try {
            $user->notify($notification);

            return true;
        } catch (Throwable $exception) {
            Log::warning('A preference email could not be sent.', [
                'user_id' => $user->id,
                'preference' => $preference,
                'notification' => $notification::class,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
