<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function send(User $user, string $preference, PreferenceMailNotification $notification): bool
    {
        if (! in_array($preference, self::ALLOWED_PREFERENCES, true)) {
            throw new InvalidArgumentException("Unsupported notification preference [{$preference}].");
        }

        if (! $user->is_active) {
            return false;
        }

        $settings = $user->preference;

        if (! $settings->{$preference}) {
            return false;
        }

        $delivered = $this->storeInAppNotification($user, $preference, $notification);

        if (! $settings->email_notifications || blank($user->email)) {
            return $delivered;
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

            return $delivered;
        }
    }

    private function storeInAppNotification(
        User $user,
        string $preference,
        PreferenceMailNotification $notification,
    ): bool {
        try {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $notification::class,
                'data' => [
                    'title' => $notification->subject,
                    'message' => $notification->lines[0] ?? 'You have a new HRMS update.',
                    'action_text' => $notification->actionText,
                    'action_url' => $notification->actionUrl,
                    'tone' => match ($preference) {
                        'attendance_reminders' => 'warning',
                        'schedule_updates' => 'primary',
                        default => Str::contains(Str::lower($notification->subject), ['approved', 'received']) ? 'success' : 'primary',
                    },
                    'icon' => match ($preference) {
                        'attendance_reminders' => 'clock',
                        'schedule_updates' => 'calendar',
                        default => 'leave',
                    },
                ],
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('An in-app notification could not be stored.', [
                'user_id' => $user->id,
                'preference' => $preference,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
