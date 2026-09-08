<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a raw audit row into the words a compliance reviewer actually uses.
 *
 * The audit table stores what the framework knows: an HTTP method, a path, a
 * route name and a status code. That is the right thing to store — it is
 * derived from nothing and cannot drift out of step with the application — but
 * it is not what an HR manager or a hospital administrator is looking for when
 * they open the page. They are looking for "who changed an employee record",
 * "which sign-ins were refused", "what happened in Scheduling last Tuesday".
 *
 * Rather than denormalise a module and an activity type into every row at write
 * time — which would freeze today's URL structure into years of immutable
 * history and leave old rows unclassified after any rename — the mapping is
 * resolved on read, here, in one place. Reclassifying is then a code change,
 * not a migration over an append-only table.
 *
 * Every classifier returns the same shape: a `key` for URLs and CSS, and a
 * `label` for people. The keys are also the accepted filter values, so what the
 * page shows and what the query filters on cannot drift apart.
 */
final class AuditActivity
{
    /**
     * Modules, keyed by the value used in the filter dropdown.
     *
     * The prefixes are matched against the first segment of the recorded path,
     * never against the route name: unnamed routes (the POST /login handler,
     * most notably) record no route name at all, and a sign-in is exactly the
     * event this page must never lose.
     *
     * @var array<string, array{label: string, prefixes: list<string>}>
     */
    public const MODULES = [
        'authentication' => [
            'label' => 'Authentication',
            'prefixes' => [
                'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password',
                'confirm-password', 'two-factor', 'two-factor-challenge', 'verify-email', 'email',
            ],
        ],
        'employees' => [
            'label' => 'Employee Records',
            'prefixes' => ['employees', 'records'],
        ],
        'organization' => [
            'label' => 'Organization',
            'prefixes' => ['organization', 'departments', 'positions'],
        ],
        'scheduling' => [
            'label' => 'Scheduling',
            'prefixes' => [
                'schedules', 'shifts', 'recurring-schedules', 'schedule-preferences', 'shift-swaps',
                'schedule-locks', 'schedule-day-offs', 'schedule-compliance-reviews', 'override',
            ],
        ],
        'attendance' => [
            'label' => 'Time & Attendance',
            'prefixes' => ['attendance', 'check-in', 'check-out', 'qr-scan', 'state'],
        ],
        'timesheets' => [
            'label' => 'Timesheets',
            'prefixes' => ['timesheets'],
        ],
        'leave' => [
            'label' => 'Leave Management',
            'prefixes' => ['leaves', 'leave-types', 'leave-attachments'],
        ],
        'reports' => [
            'label' => 'Reports & Analytics',
            'prefixes' => ['reports', 'analytics'],
        ],
        'administration' => [
            'label' => 'Administration',
            'prefixes' => ['settings', 'integrations', 'profile', 'notifications', 'audit-logs', 'search'],
        ],
    ];

    /**
     * Activity types — what kind of thing happened, independent of where it
     * happened. "Show me every deletion this month, anywhere" is a real
     * compliance question and no module filter can answer it.
     *
     * @var array<string, string>
     */
    public const ACTIVITIES = [
        'authentication' => 'Sign-in activity',
        'approval' => 'Approval or review',
        'export' => 'Data export',
        'delete' => 'Record deleted',
        'update' => 'Record updated',
        'create' => 'Record created',
    ];

    /**
     * Status groups. Split finer than success/failure because the failure modes
     * call for different responses: a 3xx is the ordinary outcome of a
     * successful form post in this app, a 401/403 is somebody being refused,
     * and a 5xx is the system itself breaking.
     *
     * @var array<string, string>
     */
    public const STATUS_GROUPS = [
        'success' => 'Successful (2xx)',
        'redirect' => 'Redirected (3xx)',
        'unauthorized' => 'Refused or expired (401, 403, 419, 429)',
        'failed' => 'Failed request (4xx)',
        'error' => 'Server error (5xx)',
    ];

    /** Words in a route name that mark a decision somebody made about somebody else's request. */
    private const APPROVAL_WORDS = ['approve', 'reject', 'review', 'decline', 'cancel', 'unlock', 'release'];

    /** @return array{key: string, label: string} */
    public static function module(AuditLog $log): array
    {
        $segment = strtolower((string) strtok(ltrim((string) $log->path, '/'), '/'));

        foreach (self::MODULES as $key => $module) {
            if (in_array($segment, $module['prefixes'], true)) {
                return ['key' => $key, 'label' => $module['label']];
            }
        }

        return ['key' => 'other', 'label' => 'Other'];
    }

    /** @return array{key: string, label: string} */
    public static function activity(AuditLog $log): array
    {
        $action = strtolower((string) $log->action);
        $path = strtolower((string) $log->path);
        $key = match (true) {
            self::module($log)['key'] === 'authentication' => 'authentication',
            str_contains($path, 'export') || str_contains($action, 'export') => 'export',
            self::mentionsApproval($action) => 'approval',
            $log->method === 'DELETE' => 'delete',
            in_array($log->method, ['PUT', 'PATCH'], true) => 'update',
            $log->method === 'POST' => 'create',
            default => 'other',
        };

        return ['key' => $key, 'label' => self::ACTIVITIES[$key] ?? 'Other activity'];
    }

    /**
     * @return array{key: string, label: string, tone: string}
     *
     * `tone` names a badge style rather than a colour, so the palette stays in
     * the stylesheet. Colour is never the only carrier here — the label is
     * printed beside the code for anyone who cannot separate the green pill
     * from the red one.
     */
    public static function status(?int $status): array
    {
        return match (true) {
            $status === null => ['key' => 'unknown', 'label' => 'Not recorded', 'tone' => 'neutral'],
            $status >= 500 => ['key' => 'error', 'label' => 'Server error', 'tone' => 'error'],
            in_array($status, [401, 419], true) => ['key' => 'unauthorized', 'label' => 'Not authenticated', 'tone' => 'blocked'],
            in_array($status, [403, 429], true) => ['key' => 'unauthorized', 'label' => 'Blocked', 'tone' => 'blocked'],
            $status >= 400 => ['key' => 'failed', 'label' => 'Failed', 'tone' => 'failed'],
            $status >= 300 => ['key' => 'redirect', 'label' => 'Completed', 'tone' => 'redirect'],
            default => ['key' => 'success', 'label' => 'Successful', 'tone' => 'success'],
        };
    }

    /**
     * A short human phrase for the action, so the table's primary column reads
     * without the reader having to decode `leaves.approve` or `post:login`.
     */
    public static function label(AuditLog $log): string
    {
        $action = (string) $log->action;

        // Unnamed routes are recorded by the middleware as "post:login". There
        // is no route name to prettify, so the method prefix is dropped — it is
        // already shown in the Request column — and the path carries the label.
        if (str_contains($action, ':')) {
            $action = str_replace('/', ' ', substr($action, strpos($action, ':') + 1));
        }

        return str($action)->replace(['.', '-', '_'], ' ')->squish()->title()->toString();
    }

    /** Rows a reviewer should not have to hunt for: anything the system refused or broke on. */
    public static function needsAttention(AuditLog $log): bool
    {
        return ($log->response_status ?? 0) >= 400;
    }

    /** @param  Builder<AuditLog>  $query */
    public static function scopeModule(Builder $query, string $module): void
    {
        $prefixes = self::MODULES[$module]['prefixes'] ?? null;

        // "Other" is defined by exclusion — everything no module claims — so it
        // is the one case that cannot be expressed as a list of prefixes.
        if ($prefixes === null) {
            $known = collect(self::MODULES)->pluck('prefixes')->flatten()->all();
            $query->where(function (Builder $inner) use ($known): void {
                foreach ($known as $prefix) {
                    $inner->where('path', '!=', $prefix)->where('path', 'not like', $prefix.'/%');
                }
            });

            return;
        }

        $query->where(function (Builder $inner) use ($prefixes): void {
            foreach ($prefixes as $prefix) {
                // Exact segment or segment-plus-child, never a bare LIKE: a
                // "login" prefix must not sweep in a "login-attempts" route
                // added later.
                $inner->orWhere('path', $prefix)->orWhere('path', 'like', $prefix.'/%');
            }
        });
    }

    /** @param  Builder<AuditLog>  $query */
    public static function scopeActivity(Builder $query, string $activity): void
    {
        match ($activity) {
            'authentication' => self::scopeModule($query, 'authentication'),
            'export' => $query->where(function (Builder $inner): void {
                $inner->where('path', 'like', '%export%')->orWhere('action', 'like', '%export%');
            }),
            'approval' => $query->where(function (Builder $inner): void {
                foreach (self::APPROVAL_WORDS as $word) {
                    $inner->orWhere('action', 'like', '%'.$word.'%');
                }
            }),
            'delete' => $query->where('method', 'DELETE'),
            'update' => $query->whereIn('method', ['PUT', 'PATCH']),
            // A POST into an authentication path is a sign-in, not a record
            // being created; lumping the two together buries genuine creations
            // under login noise, which is most of this table by volume.
            'create' => $query->where('method', 'POST')->whereNot(
                fn (Builder $inner) => self::scopeModule($inner, 'authentication')
            ),
            default => null,
        };
    }

    /** @param  Builder<AuditLog>  $query */
    public static function scopeStatus(Builder $query, string $group): void
    {
        match ($group) {
            'success' => $query->whereBetween('response_status', [200, 299]),
            'redirect' => $query->whereBetween('response_status', [300, 399]),
            'unauthorized' => $query->whereIn('response_status', [401, 403, 419, 429]),
            'failed' => $query->whereBetween('response_status', [400, 499])->whereNotIn('response_status', [401, 403, 419, 429]),
            'error' => $query->where('response_status', '>=', 500),
            default => null,
        };
    }

    private static function mentionsApproval(string $action): bool
    {
        foreach (self::APPROVAL_WORDS as $word) {
            if (str_contains($action, $word)) {
                return true;
            }
        }

        return false;
    }
}
