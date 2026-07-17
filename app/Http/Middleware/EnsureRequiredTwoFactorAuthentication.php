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

    private function isEnrollmentRoute(Request $request): bool
    {
        return $request->routeIs('settings.*', 'two-factor.settings.*', 'logout');
    }
}
