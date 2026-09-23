<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * What a browser calls itself, across sessions.
 *
 * A session cookie says "this tab, until it is closed". This says "this
 * browser, for a year" — which is a different and longer-lived question, and two
 * features now need the same answer to it: the single-active-session rule, which
 * must know that a device coming back to the login page is the same device that
 * was already holding the account, and the trusted-mobile-device rule, which
 * must know that the phone skipping the authenticator code is the phone whose
 * app lock was set up.
 *
 * It was private to {@see ActiveDeviceSessionService} until the second caller
 * arrived. Both must read the identical value: minting a second cookie would
 * mean a phone that is "the same device" to one rule and a stranger to the
 * other.
 *
 * The identifier is random, meaningless on its own, and never derived from the
 * user agent or the IP — a fingerprint of those would follow the person to a
 * device they are not holding, and would drift on every browser update.
 */
class DeviceIdentityService
{
    /**
     * Kept far longer than any one session so that it is still there to be
     * recognised by after the session is not.
     */
    public const COOKIE = 'hrms_device';

    private const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * The device recorded by hash, never in the clear: holding the value in the
     * cookie is the whole of what makes a browser this browser, so a stored
     * copy of it would be a stored copy of the credential.
     */
    public function hash(Request $request): string
    {
        return hash('sha256', $this->id($request));
    }

    /**
     * What this browser calls itself, minting the name if it has none yet.
     *
     * Answered once per request from the cookie already queued, so that the
     * device a sign-in is weighed against is the same device it is then
     * recorded on.
     */
    public function id(Request $request): string
    {
        $existing = $request->cookie(self::COOKIE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $queued = Cookie::queued(self::COOKIE);

        if ($queued !== null && $queued->getValue() !== '') {
            return (string) $queued->getValue();
        }

        $id = Str::random(64);

        Cookie::queue(
            self::COOKIE,
            $id,
            self::COOKIE_MINUTES,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            config('session.same_site', 'lax'),
        );

        return $id;
    }
}
