<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\ActiveDeviceSessionService;
use App\Services\RememberedLoginService;
use App\Services\TwoFactorSecurityService;
use App\Support\SessionNotice;
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
            // Redirected here by the server, or sent here by a browser that
            // had already shown the dialog and knows why it is leaving.
            'sessionNotice' => SessionNotice::fromRequestValue(
                $request->session()->get(SessionNotice::FLASH_KEY, $request->query('reason')),
            ),
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

        // Correct credentials are not enough while the account is open
        // somewhere else. Nobody inherits the session: it closes there, and
        // this attempt is turned away rather than let through.
        if ($activeSession->openElsewhere($user, $request)) {
            return $activeSession->closeEverywhere($user, $request);
        }

        $rememberedLogin->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        // Claimed last, after the session has been regenerated, so the token
        // lands in the session this browser will keep — and only ever once the
        // slot has been found free above.
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
