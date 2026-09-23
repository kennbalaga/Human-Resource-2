<?php

namespace App\Services\Security;

use App\Models\TrustedMobileDevice;
use App\Models\User;
use App\Services\DeviceIdentityService;
use App\Support\MobileDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The phone that no longer has to type an authenticator code.
 *
 * ---- The argument for this existing ----
 *
 * An authenticator code protects against somebody who has the password and not
 * the phone. Once the app lock is set up, the phone itself is protecting against
 * somebody who has the phone — a six-digit PIN or the handset's own fingerprint
 * sensor, asked for on every launch and every return from the background. Making
 * that person also read a code out of an app on the same locked handset is not a
 * second factor; it is the first factor twice, and the cost is real: it is asked
 * of a nurse on a ward, one-handed, at the start of a shift.
 *
 * So this trades the code for the lock, on that handset only. A computer is
 * untouched — it has no app lock to stand in for anything — and so is any phone
 * whose lock has not been set up.
 *
 * ---- Why it is safe to trade ----
 *
 * The trust is two things at once, and losing either ends it:
 *
 *   - the year-long hrms_device cookie, which is httpOnly and therefore not
 *     readable by script, and which identifies this browser and no other;
 *   - a random token handed out once and kept in this device's localStorage
 *     beside the lock record, which is destroyed by removing the lock, by
 *     clearing site data, and by uninstalling the app.
 *
 * An attacker with the password alone has neither. An attacker who copies the
 * token out of the phone's storage has no matching cookie. A phone whose storage
 * was wiped has no token, so the code comes back the same day the lock does.
 * And because the row is written only from inside an authenticated session, it
 * cannot be created by anybody who has not already got all the way in.
 */
class TrustedMobileDeviceService
{
    public function __construct(
        private readonly DeviceIdentityService $deviceIdentity,
    ) {}

    /**
     * Trust this browser for this account, and hand back the token it must keep.
     *
     * The token is returned in the clear exactly once, here, and only the digest
     * is stored — the same shape as a password reset link. Arming twice replaces
     * the previous token rather than adding a second working one.
     */
    public function arm(User $user, Request $request): string
    {
        $token = Str::random(64);

        TrustedMobileDevice::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'device_hash' => $this->deviceIdentity->hash($request),
            ],
            [
                'token_hash' => $this->digest($token),
                'armed_at' => now(),
                'last_used_at' => null,
            ],
        );

        Log::notice('HRMS trusted a mobile device for sign-in.', [
            'event' => 'mobile_trust.armed',
            'user_id' => $user->getKey(),
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        return $token;
    }

    /**
     * Whether one of the tokens this browser offered stands for a live trust.
     *
     * A list rather than a single value because the sign-in form does not know
     * which account is about to be named: a shared ward handset may hold a token
     * for each of the staff who set a PIN on it, and they are all offered. Only a
     * token belonging to the account being signed in can match, so offering the
     * others gives nothing away.
     *
     * @param  array<int, string>  $offered
     */
    public function trusts(User $user, Request $request, array $offered): bool
    {
        if ($offered === []) {
            return false;
        }

        /*
         * The trade is for a handset, and only a handset. A token lifted out of a
         * phone's storage and replayed from a desktop browser is refused here even
         * before the device hash is consulted, so the skip cannot outlive the kind
         * of device whose lock justified it.
         *
         * The cost is one edge: a phone toggled to "Request desktop site" presents a
         * desktop agent and types a code, although its lock is still in front of the
         * app. That is the safe direction to be wrong in, and it is one tap to undo.
         */
        if (! MobileDevice::is($request)) {
            return false;
        }

        $trust = TrustedMobileDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_hash', $this->deviceIdentity->hash($request))
            ->first();

        if ($trust === null || $this->expired($trust)) {
            return false;
        }

        foreach ($offered as $token) {
            if (is_string($token) && hash_equals((string) $trust->token_hash, $this->digest($token))) {
                // Written past the model so that being used does not read as
                // being edited, and so a sign-in costs one UPDATE rather than a
                // full save of a row nothing else changed.
                TrustedMobileDevice::query()
                    ->whereKey($trust->getKey())
                    ->update(['last_used_at' => now()]);

                return true;
            }
        }

        return false;
    }

    /**
     * Drop this browser's trust — the app lock was removed, or this device was
     * found to be holding a token the server no longer has a row for.
     */
    public function revoke(User $user, Request $request): void
    {
        $deleted = TrustedMobileDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_hash', $this->deviceIdentity->hash($request))
            ->delete();

        if ($deleted > 0) {
            Log::notice('HRMS withdrew trust from a mobile device.', [
                'event' => 'mobile_trust.revoked',
                'user_id' => $user->getKey(),
                'ip_address' => $request->ip(),
                'request_id' => $request->headers->get('X-Request-ID'),
            ]);
        }
    }

    /**
     * Drop every trusted device for this account at once.
     *
     * Reached when the account's two-factor enrollment is reset by an
     * administrator, which is the action taken when a phone is lost. A reset that
     * left the lost handset able to skip the code would be the opposite of what
     * was asked for.
     */
    public function revokeAll(User $user): void
    {
        $deleted = TrustedMobileDevice::query()->where('user_id', $user->getKey())->delete();

        if ($deleted > 0) {
            Log::notice('HRMS withdrew trust from every mobile device on an account.', [
                'event' => 'mobile_trust.revoked_all',
                'user_id' => $user->getKey(),
                'devices' => $deleted,
            ]);
        }
    }

    /**
     * A handset that stopped being used. The lock is normally what ends a trust,
     * and a phone in a drawer never removes its lock, so the clock is the only
     * thing that will.
     */
    private function expired(TrustedMobileDevice $trust): bool
    {
        $days = max(1, (int) config('security.mobile.trust_days', 180));
        $since = $trust->last_used_at ?? $trust->armed_at;

        return $since === null || $since->lessThan(now()->subDays($days));
    }

    /**
     * Keyed on the app key rather than a bare hash, so that a leaked copy of the
     * table is not a leaked set of tokens: without APP_KEY the digests cannot be
     * reproduced from a guessed or stolen token.
     */
    private function digest(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key', 'missing-app-key'));
    }
}
