<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use App\Services\Organization\NotificationEmailSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PreferenceNotificationService
{
    /**
     * The kinds of update this sends, which decide the icon, tone and filter
     * an in-app notification is filed under.
     *
     * These were once per-user email switches as well. They are not any more:
     * whether HRMS emails at all is one System Administrator's decision for
     * everybody, so an employee can no longer make themselves unreachable for
     * their own schedule.
     *
     * @var array<int, string>
     */
    private const CATEGORIES = [
        'attendance_reminders',
        'schedule_updates',
        'leave_updates',
        'payroll_updates',
    ];

    public function __construct(private readonly NotificationEmailSettings $emailSettings) {}

    public function send(User $user, string $category, PreferenceMailNotification $notification): bool
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Unsupported notification category [{$category}].");
        }

        if (! $user->is_active) {
            return false;
        }

        $delivered = $this->storeInAppNotification($user, $category, $notification);

        if (! $this->emailSettings->enabled() || blank($user->email)) {
            return $delivered;
        }

        // PreferenceMailNotification is queued (ShouldQueue), so this only
        // ever catches a dispatch-time failure (e.g. the queue connection
        // itself is unreachable) — a queued job returns immediately, before
        // the mail actually sends. Real delivery failures are caught by the
        // notification's own failed() method instead.
        try {
            $user->notify($notification);

            return true;
        } catch (Throwable $exception) {
            Log::warning('A notification email could not be dispatched.', [
                'user_id' => $user->id,
                'category' => $category,
                'notification' => $notification::class,
                'error' => $exception->getMessage(),
            ]);

            return $delivered;
        }
    }

    private function storeInAppNotification(
        User $user,
        string $category,
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
                    'tone' => match ($category) {
                        'attendance_reminders' => 'warning',
                        'schedule_updates' => 'primary',
                        'payroll_updates' => 'success',
                        default => Str::contains(Str::lower($notification->subject), ['approved', 'received']) ? 'success' : 'primary',
                    },
                    'icon' => match ($category) {
                        'attendance_reminders' => 'clock',
                        'schedule_updates' => 'calendar',
                        'payroll_updates' => 'report',
                        default => 'leave',
                    },
                    'category' => match ($category) {
                        'attendance_reminders' => 'attendance',
                        'schedule_updates' => 'schedule',
                        'payroll_updates' => 'payroll',
                        default => 'leave',
                    },
                ],
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('An in-app notification could not be stored.', [
                'user_id' => $user->id,
                'category' => $category,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
