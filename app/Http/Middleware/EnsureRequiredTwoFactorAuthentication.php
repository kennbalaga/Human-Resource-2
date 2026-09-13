<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredTwoFactorAuthentication
{
    public function __construct(
        private readonly TwoFactorSecurityService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! $this->twoFactor->isRequiredFor($user)
            || $user->hasEnabledTwoFactorAuthentication()
            || $this->isEnrollmentRoute($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Two-factor authentication enrollment is required for this account.',
            ], 403);
        }

        return redirect()->to(route('settings.edit').'#two-factor')
            ->with('two_factor_required', 'Your role requires authenticator-app two-factor authentication. Complete setup to continue.');
    }

    /**
     * Routes an account that still owes enrollment can reach: the setup screen
     * itself, signing out, and the inactivity timer's keep-alive.
     *
     * The keep-alive only answers whether this session is still alive. Refusing
     * it with a 403 read, to the timer on the setup page, as a session that had
     * ended -- so an HR manager sent to enroll was signed out for "inactivity"
     * seconds after arriving, before they could scan the code.
     */
    private function isEnrollmentRoute(Request $request): bool
    {
        return $request->routeIs('settings.*', 'two-factor.settings.*', 'logout', 'session.keep-alive');
    }
}
