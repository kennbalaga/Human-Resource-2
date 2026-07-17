<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\RememberedLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request, RememberedLoginService $rememberedLogin): View
    {
        $rememberedEmployeeId = $rememberedLogin->employeeNumber($request);

        return view('auth.login', [
            'rememberedEmployeeId' => $rememberedEmployeeId,
            'rememberedEmployeeSelected' => $rememberedEmployeeId !== null,
        ]);
    }

    public function store(LoginRequest $request, RememberedLoginService $rememberedLogin): RedirectResponse
    {
        $user = $request->authenticate();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $request->boolean('remember'),
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        $rememberedLogin->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function keepAlive(): JsonResponse
    {
        return response()->json([
            'active' => true,
            'expires_in' => (int) config('session.lifetime') * 60,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('login')]);
        }

        return redirect()->route('login');
    }
}
