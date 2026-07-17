<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorSecurityService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTwoFactorEnrollment
{
    public function __construct(
        private readonly TwoFactorSecurityService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $user = $request->user();

        if ($user !== null
            && $this->twoFactor->isRequiredFor($user)
            && ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Two-factor authentication enrollment is required for this privileged account.',
            ], 403);
        }

        return $next($request);
    }
}
