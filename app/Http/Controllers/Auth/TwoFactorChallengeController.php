<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RememberedLoginService;
use App\Services\TwoFactorSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(
        Request $request,
        TwoFactorSecurityService $twoFactor,
        RememberedLoginService $rememberedLogin,
    ): RedirectResponse {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:50', 'required_without:code'],
        ]);

        $user = User::query()->with('employee')->find($request->session()->get('login.id'));

        if ($user === null || ! $user->is_active || $user->employee?->employment_status !== 'active') {
            $request->session()->forget(['login.id', 'login.remember']);

            throw ValidationException::withMessages([
                'code' => ['This account is not available for sign in.'],
            ]);
        }

        $value = (string) ($validated['code'] ?? $validated['recovery_code'] ?? '');

        if (! $twoFactor->verify($user, $value)) {
            TwoFactorAuthenticationFailed::dispatch($user);

            throw ValidationException::withMessages([
                'code' => ['The authentication code is invalid or has expired.'],
            ]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');
        $rememberedLogin->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        ValidTwoFactorAuthenticationCodeProvided::dispatch($user);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
