<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * System administrators run the platform; they do not keep the records. This is
 * the single choke point that enforces that split, so a write route added later
 * is denied by default rather than by remembering to guard it.
 *
 * Authorisation still lives in the form requests and controllers. This runs on
 * top of them, which is why it matches on route names instead of roles at the
 * call site: a new route is blocked until it is deliberately allowed here.
 */
class EnforceReadOnlyRole
{
    /**
     * Route names a read-only administrator may still POST to. Everything here
     * touches their own account or the system configuration they are hired to
     * run, never another employee's workforce records.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTES = [
        // System configuration and their own credentials.
        'settings.*',
        'two-factor.*',
        'password.*',
        'logout',
        'session.keep-alive',

        // Account and platform administration, not workforce records. Both are
        // gated to system administrators alone, so blocking them here would
        // leave nobody able to unlock a user or toggle the assistant.
        'employees.two-factor.reset',
        'integrations.ai-scheduling.update',

        // Their own employee self-service. Leave is filed and withdrawn here;
        // approving anyone's leave stays blocked, and LeaveService still holds
        // a read-only role to cancelling only its own request.
        'profile.update',
        'attendance.check-in',
        'attendance.check-out',
        'notifications.*',
        'leaves.store',
        'leaves.cancel',
        'shift-swaps.store',
        'shift-swaps.respond',
        'shift-swaps.cancel',
        'schedule-preferences.update-standing',
        'schedule-preferences.store-day-off',
        'schedule-preferences.cancel-day-off',

        // Read-only endpoints that answer with POST because they take a body.
        'schedules.conflicts',
        'analytics.ai-insights',
        'integrations.gemini.test',

        // API session handling, mirroring the web routes above.
        'api.v1.auth.token',
        'api.v1.auth.logout',
        'api.v1.security.csp-report',
    ];

    /**
     * Downloads a read-only role may not take. These are GET routes, so they are
     * denied by name rather than by method: the data is readable on screen but
     * does not leave the system as a file.
     *
     * Audit log exports are deliberately absent. Reviewing them is the system
     * administrator's own job, not workforce data.
     *
     * @var array<int, string>
     */
    public const DENIED_ROUTES = [
        'timesheets.export',
        'attendance.reports.export',
        'attendance.reports.export-pdf',
        'attendance.reports.export-excel',
        'analytics.export',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBlock($request)) {
            Log::notice('HRMS read-only role blocked a write request.', [
                'event' => 'authorization.read_only_block',
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'request_id' => $request->headers->get('X-Request-ID'),
            ]);

            abort(403, 'Your role has read-only access to this data.');
        }

        return $next($request);
    }

    private function shouldBlock(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isReadOnly()) {
            return false;
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && in_array($routeName, self::DENIED_ROUTES, true)) {
            return true;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        // An unnamed write route has no entry here and cannot be allowlisted,
        // so it is denied rather than silently permitted.
        if ($routeName === null) {
            return true;
        }

        return ! Str::is(self::ALLOWED_ROUTES, $routeName);
    }
}
