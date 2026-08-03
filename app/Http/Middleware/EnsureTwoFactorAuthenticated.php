<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $request->session()->has('two_factor_verified_at')) {
            return redirect()->route($user->two_factor_enabled_at ? 'two-factor.challenge' : 'two-factor.setup');
        }

        return $next($request);
    }
}
