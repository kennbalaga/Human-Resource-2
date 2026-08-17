<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\ActiveDeviceSessionService;
use App\Services\RememberedLoginService;
use App\Services\TwoFactorSecurityService;
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

    public function store(
        LoginRequest $request,
        RememberedLoginService $rememberedLogin,
        TwoFactorSecurityService $twoFactor,
        ActiveDeviceSessionService $activeSession,
    ): RedirectResponse {
        $user = $request->authenticate();

        if ($twoFactor->challengeRequiredFor($user)) {
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

        // Claimed last, after the session has been regenerated, so the token
        // lands in the session this browser will keep. Any device already
        // signed into this account is displaced by it.
        $activeSession->claim($user, $request);

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

    public function destroy(Request $request, ActiveDeviceSessionService $activeSession): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $activeSession->release($user, $request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('login')]);
        }

        return redirect()->route('login');
    }
}
