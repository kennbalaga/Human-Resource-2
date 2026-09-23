<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Services\Security\SecurityAlertService;
use App\Services\Security\TrustedMobileDeviceService;
use App\Services\TwoFactorSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class AdminTwoFactorController extends Controller
{
    public function __construct(
        private readonly SecurityAlertService $alerts,
        private readonly TrustedMobileDeviceService $trustedDevices,
    ) {}

    public function reset(
        Request $request,
        Employee $employee,
        TwoFactorSecurityService $twoFactor,
        DisableTwoFactorAuthentication $disable,
    ): RedirectResponse {
        abort_unless($request->user()->hasRole('system-administrator'), 403);

        $target = $employee->user;
        abort_if($target === null || $target->is($request->user()), 422, 'Use Account Settings to manage your own two-factor authentication.');

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'identity_verified' => ['accepted'],
        ]);

        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);
        $disable($target);
        $target->tokens()->delete();

        /*
         * This reset is the action taken when a phone is lost, and a phone that
         * was trusted to skip the authenticator code is exactly the device being
         * reset away from. Leaving its trust standing would mean the handset that
         * prompted the reset was the one device still able to sign in without a
         * code.
         */
        $this->trustedDevices->revokeAll($target);

        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
        }

        $target->forceFill(['remember_token' => Str::random(60)])->save();
        $this->notifyTarget($target);

        return back()->with('success', 'Two-factor authentication reset after identity verification. Existing sessions, API tokens and trusted mobile devices were revoked.');
    }

    private function notifyTarget(User $user): void
    {
        $this->alerts->alertUser(
            $user,
            'Two-factor authentication reset',
            'A System Administrator reset your two-factor authentication after identity verification. Enroll again from Account Settings.',
            'Open security settings',
            route('settings.edit').'#two-factor',
            'warning',
            'two_factor.admin_reset',
        );
    }
}
