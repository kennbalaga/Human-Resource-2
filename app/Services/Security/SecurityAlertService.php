<?php

namespace App\Services\Security;

use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SecurityAlertService
{
    /** @param array<string, mixed> $context */
    public function alertAdministrators(
        string $event,
        string $title,
        string $message,
        string $severity = 'warning',
        array $context = [],
        ?User $actor = null,
    ): void {
        $safeContext = $this->sanitizeContext($context);

        Log::warning('HRMS security event: '.$event, [
            'event' => $event,
            'severity' => $severity,
            'actor_user_id' => $actor?->id,
            'context' => $safeContext,
        ]);

        try {
            if (! Schema::hasTable('users') || ! Schema::hasTable('notifications') || ! Schema::hasTable('roles')) {
                return;
            }

            $roles = config('security.audit.alert_admin_roles', ['system-administrator']);
            $administrators = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->whereIn('slug', $roles))
                ->get();

            foreach ($administrators as $administrator) {
                $this->alertUser(
                    $administrator,
                    $title,
                    $message,
                    'Review audit logs',
                    url('/audit-logs'),
                    in_array($severity, ['critical', 'danger'], true) ? 'danger' : 'warning',
                    $event,
                );
            }
        } catch (Throwable $exception) {
            Log::error('A security alert could not be stored as an in-app notification.', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * One security event, in the app and in the person's inbox.
     *
     * The bell only reaches somebody who opens HRMS, and the events that come
     * through here — a two-factor reset, a lockout, an alert an administrator
     * has to act on — are exactly the ones that matter on a day off. So the
     * email is not gated on the notification preferences the way schedule and
     * leave mail is: a person who silenced their updates has not agreed to
     * miss a change made to their own account's security.
     *
     * The in-app copy is written first and separately, so a mail server that
     * is down costs the email and not the record of the event.
     */
    public function alertUser(
        User $user,
        string $title,
        string $message,
        string $actionText,
        string $actionUrl,
        string $tone = 'warning',
        ?string $event = null,
    ): void {
        $title = mb_substr($title, 0, 150);
        $message = mb_substr($message, 0, 500);

        try {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => self::class,
                'data' => [
                    'title' => $title,
                    'message' => $message,
                    'action_text' => $actionText,
                    'action_url' => $actionUrl,
                    'tone' => $tone,
                    'icon' => 'shield',
                    'category' => 'security',
                    'security_event' => $event,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('A security alert could not be stored as an in-app notification.', [
                'event' => $event,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }

        if (blank($user->email)) {
            return;
        }

        try {
            $user->notify(new SecurityAlertNotification($title, $message, $actionText, $actionUrl));
        } catch (Throwable $exception) {
            Log::warning('A security email could not be dispatched.', [
                'event' => $event,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $context @return array<string, bool|float|int|string|null> */
    private function sanitizeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            $safe[mb_substr($key, 0, 100)] = is_string($value)
                ? mb_substr($value, 0, 500)
                : $value;
        }

        return $safe;
    }
}
