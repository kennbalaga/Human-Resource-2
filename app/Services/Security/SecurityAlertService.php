<?php

namespace App\Services\Security;

use App\Models\User;
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
                $administrator->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => self::class,
                    'data' => [
                        'title' => mb_substr($title, 0, 150),
                        'message' => mb_substr($message, 0, 500),
                        'action_text' => 'Review audit logs',
                        'action_url' => url('/audit-logs'),
                        'tone' => in_array($severity, ['critical', 'danger'], true) ? 'danger' : 'warning',
                        'icon' => 'shield',
                        'category' => 'security',
                        'security_event' => $event,
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('A security alert could not be stored as an in-app notification.', [
                'event' => $event,
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
