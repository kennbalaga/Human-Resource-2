<?php

namespace App\Http\Middleware;

use App\Support\SessionNotice;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account closed mid-session stops working on its next request.
 *
 * Sign-in already refuses anybody whose employment is not active, and closes
 * with the record when HR terminates or archives it. But a person already
 * signed in when that happens keeps their session: nothing between the login
 * form and the session's own expiry ever asks whether the account is still
 * open, so somebody terminated at 9am carries on reading and writing records
 * until they happen to close the browser.
 *
 * The check costs no query. `is_active` is a column on the user the auth guard
 * has already loaded, and HR's own screens are what keep it true — the employee
 * form derives it from employment status, and archiving clears it outright.
 */
class EnsureAccountIsStillOpen
{
    public const NOTICE = SessionNotice::AccountClosed;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only an explicit false is a closed account. A model built or loaded
        // without the column reads null, and signing somebody out over a value
        // nobody set would turn an incomplete query into a lockout.
        $isClosed = $user !== null && $user->is_active === false;

        // Signing out is the one thing a closed account should still be able
        // to finish.
        if (! $isClosed || $request->routeIs('logout')) {
            return $next($request);
        }

        Log::info('HRMS session ended because the account is no longer active.', [
            'event' => 'session.account_closed',
            'user_id' => $user->getKey(),
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The screen finds out on its own: the session heartbeat runs every
        // few seconds, and this is the answer it gets. It carries the reason
        // so the dialog says the account was closed rather than blaming an
        // idle timeout and inviting a sign-in that cannot succeed.
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => self::NOTICE->message(),
                'title' => self::NOTICE->title(),
                'reason' => self::NOTICE->value,
                'redirect' => route('login', ['reason' => self::NOTICE->value]),
            ], 401);
        }

        return redirect()->route('login')->with(SessionNotice::FLASH_KEY, self::NOTICE->value);
    }
}
