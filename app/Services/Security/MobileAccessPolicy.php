<?php

namespace App\Services\Security;

use App\Models\User;
use App\Support\MobileDevice;
use Illuminate\Http\Request;

/**
 * Who may use this app on a phone, and whose phone may install it.
 *
 * One class for both questions because they are the same question asked at two
 * moments. The install prompt is offered on a Tuesday and the sign-in is
 * refused on a Friday; if the two were decided in different places, the app
 * would eventually invite somebody to install something they are then turned
 * away from — which reads as a bug and is worse than never offering at all.
 *
 * A restricted role is restricted on every device it reaches, so an account
 * holding both `employee` and `hr-manager` is refused: the roles add reach, and
 * the wider one is what the phone would be carrying.
 */
class MobileAccessPolicy
{
    /**
     * @return array<int, string>
     */
    public function restrictedRoles(): array
    {
        return (array) config('security.mobile.restricted_roles', []);
    }

    /**
     * Whether this account is one of the desk-bound ones.
     *
     * Named for the account rather than the request because it is true of the
     * account wherever it is: it is what decides the install offer on a
     * desktop, where there is no phone in the picture at all.
     */
    public function restricts(User $user): bool
    {
        $roles = $this->restrictedRoles();

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    /**
     * Whether this particular request must be turned away: a restricted account
     * arriving from a phone or a tablet.
     */
    public function denies(Request $request, User $user): bool
    {
        return $this->restricts($user) && MobileDevice::is($request);
    }

    /**
     * Whether this account may be offered the installable app at all.
     *
     * Also the answer to "should this page advertise a manifest": withholding it
     * is what stops Chromium's own address-bar install button from appearing,
     * which no amount of suppressing our own banner would have covered.
     */
    public function allowsInstall(User $user): bool
    {
        return ! $this->restricts($user);
    }

    /**
     * What the person is told, in the one place both the sign-in refusal and the
     * blocked page read it from.
     */
    public function refusalMessage(): string
    {
        return 'This account cannot be used on a phone or tablet. Sign in from a computer instead.';
    }
}
