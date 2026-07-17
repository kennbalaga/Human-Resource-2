<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class TwoFactorSettingsController extends Controller
{
    public function enable(
        Request $request,
        TwoFactorSecurityService $twoFactor,
        EnableTwoFactorAuthentication $enable,
    ): RedirectResponse {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);
        $enable($request->user());

        return redirect()->to(route('settings.edit').'#two-factor')
            ->with('success', 'Scan the QR code and enter a code to finish enabling two-factor authentication.');
    }

    public function confirm(
        Request $request,
        TwoFactorSecurityService $twoFactor,
        ConfirmTwoFactorAuthentication $confirm,
    ): RedirectResponse {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);
        $confirm($request->user(), $validated['code']);
        $request->user()->tokens()->delete();

        return redirect()->to(route('settings.edit').'#two-factor')
            ->with('success', 'Two-factor authentication is now protecting your account. Existing API tokens were revoked.')
            ->with('two_factor_recovery_codes', $request->user()->fresh()->recoveryCodes());
    }

    public function recoveryCodes(Request $request, TwoFactorSecurityService $twoFactor): RedirectResponse
    {
        $validated = $this->validateProtectedAction($request);
        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);
        $twoFactor->ensureValid($request->user(), $validated['verification_code']);

        return redirect()->to(route('settings.edit').'#two-factor')
            ->with('two_factor_recovery_codes', $request->user()->fresh()->recoveryCodes());
    }

    public function regenerateRecoveryCodes(
        Request $request,
        TwoFactorSecurityService $twoFactor,
        GenerateNewRecoveryCodes $generate,
    ): RedirectResponse {
        $validated = $this->validateProtectedAction($request);
        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);
        $twoFactor->ensureValid($request->user(), $validated['verification_code']);
        $generate($request->user());

        return redirect()->to(route('settings.edit').'#two-factor')
            ->with('success', 'New recovery codes generated. Previous recovery codes no longer work.')
            ->with('two_factor_recovery_codes', $request->user()->fresh()->recoveryCodes());
    }

    public function disable(
        Request $request,
        TwoFactorSecurityService $twoFactor,
        DisableTwoFactorAuthentication $disable,
    ): RedirectResponse {
        $rules = ['current_password' => ['required', 'string']];

        if ($request->user()->hasEnabledTwoFactorAuthentication()) {
            $rules['verification_code'] = ['required', 'string', 'max:50'];
        }

        $validated = $request->validate($rules);
        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);

        if ($request->user()->hasEnabledTwoFactorAuthentication()) {
            $twoFactor->ensureValid($request->user(), $validated['verification_code']);
        }

        $disable($request->user());
        $request->user()->tokens()->delete();
        $request->session()->regenerate();

        return redirect()->to(route('settings.edit').'#two-factor')
            ->with('success', 'Two-factor authentication disabled. Existing API tokens were revoked.');
    }

    /** @return array{current_password: string, verification_code: string} */
    private function validateProtectedAction(Request $request): array
    {
        return $request->validate([
            'current_password' => ['required', 'string'],
            'verification_code' => ['required', 'string', 'max:50'],
        ]);
    }
}
