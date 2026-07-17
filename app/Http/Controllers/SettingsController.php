<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use App\Http\Requests\Settings\UpdatePreferencesRequest;
use App\Http\Requests\Settings\UpdateThemeRequest;
use App\Services\TwoFactorSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request, TwoFactorSecurityService $twoFactor): View
    {
        $user = $request->user()->load(['roles', 'employee', 'preference']);
        $pendingEncryptionState = $twoFactor->normalizePendingEnrollment($user);
        $user->refresh()->load(['roles', 'employee', 'preference']);

        return view('settings.edit', [
            'user' => $user,
            'preference' => $user->preference,
            'currentRole' => $user->roles->pluck('name')->join(', ') ?: 'Employee',
            'notifications' => collect(),
            'timezones' => ['Asia/Manila' => 'Philippines (UTC+8)', 'Asia/Singapore' => 'Singapore (UTC+8)', 'UTC' => 'UTC'],
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'twoFactorRequired' => $twoFactor->isRequiredFor($user),
            'twoFactorSetupReset' => $pendingEncryptionState === 'reset',
            'twoFactorQrCode' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null
                ? $user->twoFactorQrCodeSvg()
                : null,
        ]);
    }

    public function updateAccount(UpdateAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('success', 'Account email updated successfully.');
    }

    public function updatePreferences(UpdatePreferencesRequest $request): RedirectResponse
    {
        $request->user()->preference()->updateOrCreate([], $request->validated());

        return back()->with('success', 'Preferences saved successfully.');
    }

    public function updateTheme(UpdateThemeRequest $request): JsonResponse
    {
        $request->user()->preference()->updateOrCreate([], $request->validated());

        return response()->json(['message' => 'Appearance updated.', 'theme' => $request->validated('theme')]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        $request->user()->tokens()->delete();
        $request->session()->regenerate();

        return back()->with('success', 'Password updated. Existing API tokens were revoked.');
    }
}
