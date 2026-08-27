<?php

namespace App\Http\Middleware;

use App\Services\RememberedLoginService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PreventRememberedAuthentication
{
    public function __construct(
        private readonly RememberedLoginService $rememberedLogin,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! Auth::guard('web')->viaRemember() || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($user->is_active && $user->employee()->where('employment_status', 'active')->exists()) {
            $this->rememberedLogin->refreshIdentity($request, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => 'Your session has expired. Sign in again to continue.',
                'redirect' => route('login'),
            ], 401);
        }

        return new RedirectResponse(route('login'));
    }
}
