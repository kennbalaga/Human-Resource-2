<?php

namespace App\Services;

use App\Http\Middleware\EnsureSingleActiveSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Holds an account to one device at a time.
 *
 * Signing in mints a token that lives in that browser's session and on the
 * account itself. Signing in somewhere else mints a new one, so the device
 * still carrying the old token no longer matches the account and is signed out
 * by {@see EnsureSingleActiveSession} the moment it next
 * speaks to the server — which is the moment it next tries to do anything,
 * since nothing here happens without a request.
 */
class ActiveDeviceSessionService
{
    public const SESSION_KEY = 'auth.active_session_token';

    /**
     * Take the account's single session slot for this device, displacing
     * whichever device held it before.
     */
    public function claim(User $user, Request $request): void
    {
        $token = Str::random(64);

        $request->session()->put(self::SESSION_KEY, $token);
        $user->forceFill(['active_session_token' => $token])->save();
    }

    /**
     * Whether this session has since been displaced by a sign-in elsewhere.
     *
     * An account holding no token holds no claim at all: it signed in before
     * this rule existed, and is left alone rather than signed out for a device
     * it never had.
     */
    public function displaced(User $user, Request $request): bool
    {
        $claimed = $user->active_session_token;

        if (! is_string($claimed) || $claimed === '') {
            return false;
        }

        return ! hash_equals($claimed, (string) $request->session()->get(self::SESSION_KEY, ''));
    }

    /**
     * Give up the slot on sign-out, but only if this device still holds it —
     * a displaced device signing itself out must not clear the claim of the
     * device that displaced it.
     */
    public function release(User $user, Request $request): void
    {
        if ($this->displaced($user, $request)) {
            return;
        }

        $user->forceFill(['active_session_token' => null])->save();
    }
}
