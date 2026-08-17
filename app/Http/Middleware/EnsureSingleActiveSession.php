<?php

namespace App\Http\Middleware;

use App\Services\ActiveDeviceSessionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One device per account: signing in somewhere else ends the session here.
 *
 * Workforce records are read on shared and personal devices alike, and an
 * account left signed in on a ward terminal is the same account someone signs
 * into at home. Rather than let both run, the newer sign-in wins and this
 * middleware retires the older session on its next request — which is every
 * request it would have used to do anything.
 */
class EnsureSingleActiveSession
{
    public const MESSAGE = 'You were signed out because this account signed in on another device.';

    public function __construct(
        private readonly ActiveDeviceSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Signing out is what a displaced device should still be able to do
        // uninterrupted; the controller releases nothing it no longer holds.
        if ($user === null || $request->routeIs('logout') || ! $this->sessions->displaced($user, $request)) {
            return $next($request);
        }

        Log::info('HRMS session displaced by a sign-in on another device.', [
            'event' => 'session.displaced',
            'user_id' => $user->getKey(),
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => self::MESSAGE,
                'reason' => 'signed_in_elsewhere',
                'redirect' => route('login'),
            ], 401);
        }

        return redirect()->route('login')->with('notice', self::MESSAGE);
    }
}
