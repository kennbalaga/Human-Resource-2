<?php

namespace App\Http\Middleware;

use App\Http\Requests\Auth\LoginRequest;
use App\Services\ActiveDeviceSessionService;
use App\Services\Security\MobileAccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the desk-bound roles off phones, on every request rather than only at
 * sign-in.
 *
 * The sign-in refusal in {@see LoginRequest} is the door;
 * this is the rest of the wall. It matters for three cases the door cannot cover:
 * a session that was already open when this rule was deployed, a session started
 * on a computer whose cookies were then carried to a phone, and an account whose
 * role was widened to hr-manager while its owner was signed in on a handset.
 *
 * Being turned away ends the session rather than merely hiding the page. A
 * restricted account left signed in on a phone is the thing being prevented, so
 * leaving it signed in and showing a notice over it would prevent nothing.
 */
class RestrictMobileAccessByRole
{
    public function __construct(
        private readonly MobileAccessPolicy $policy,
        private readonly ActiveDeviceSessionService $activeSession,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->isExemptRoute($request) || ! $this->policy->denies($request, $user)) {
            return $next($request);
        }

        Log::notice('HRMS refused a restricted role on a mobile device.', [
            'event' => 'mobile_access.refused',
            'user_id' => $user->getKey(),
            'route_name' => $request->route()?->getName(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        $this->signOut($request);

        /*
         * The keep-alive timer and the download modal both speak JSON, and a
         * redirect answered to fetch() is followed silently — the page would sit
         * there looking signed in while the session behind it was gone. Naming
         * the destination lets the caller navigate to it.
         */
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->policy->refusalMessage(),
                'redirect' => route('mobile.unavailable'),
            ], 403);
        }

        return redirect()->route('mobile.unavailable');
    }

    /**
     * Routes a refused session may still reach: the page explaining the refusal,
     * the sign-out the browser-side check performs, and signing out by hand. All
     * three are how somebody leaves; blocking them would strand them.
     */
    private function isExemptRoute(Request $request): bool
    {
        return $request->routeIs('mobile.unavailable', 'mobile.unavailable.store', 'logout');
    }

    private function signOut(Request $request): void
    {
        $user = $request->user();

        if ($user !== null) {
            // Released first, while the session still exists to be recognised by:
            // the claim is keyed on this session's token, and invalidating the
            // session before releasing it would leave the account recorded as
            // open on a device that is no longer signed in.
            $this->activeSession->release($user, $request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
