<?php

namespace App\Http\Middleware;

use App\Services\ActiveDeviceSessionService;
use App\Support\SessionNotice;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One device per account: an attempt to open it somewhere else ends the
 * session here.
 *
 * Workforce records are read on shared and personal devices alike, and an
 * account left signed in on a ward terminal is the same account someone signs
 * into at home. Rather than let both run — or let the newer one silently
 * inherit the older one's screen — the account closes on both sides, and this
 * middleware retires the older session on its next request, which is every
 * request it would have used to do anything.
 */
class EnsureSingleActiveSession
{
    public const NOTICE = SessionNotice::SignedInElsewhere;

    public function __construct(
        private readonly ActiveDeviceSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Signing out is what a device whose hold has ended should still be
        // able to do uninterrupted; the controller releases nothing it no
        // longer holds.
        if ($user === null || $request->routeIs('logout') || ! $this->sessions->displaced($user, $request)) {
            return $next($request);
        }

        Log::info('HRMS session ended by a sign-in attempt on another device.', [
            'event' => 'session.displaced',
            'user_id' => $user->getKey(),
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            // The browser is mid-fetch and will show the dialog itself, then
            // send the person to the login page carrying the same reason —
            // the flash below would have been spent by then.
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
