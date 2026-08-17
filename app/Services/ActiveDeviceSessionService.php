<?php

namespace App\Services;

use App\Http\Middleware\EnsureSingleActiveSession;
use App\Models\User;
use App\Support\SessionNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Holds an account to one device at a time.
 *
 * Signing in mints a token that lives in that browser's session and on the
 * account itself. A second device arriving does not take the account over — it
 * closes it. The claim is dropped, so the device still carrying the old token
 * no longer matches the account and is signed out by
 * {@see EnsureSingleActiveSession} the moment it next speaks to the server,
 * and the device that arrived is turned away without ever being signed in.
 *
 * Neither side gets to quietly keep the account while the other wonders what
 * happened: the person at each screen is told which of the two they are.
 */
class ActiveDeviceSessionService
{
    public const SESSION_KEY = 'auth.active_session_token';

    /**
     * Take the account's single session slot for this device.
     *
     * Only ever reached once the slot is free — {@see openElsewhere} is the
     * question asked first.
     */
    public function claim(User $user, Request $request): void
    {
        $token = Str::random(64);

        $request->session()->put(self::SESSION_KEY, $token);
        $user->forceFill(['active_session_token' => $token])->save();
    }

    /**
     * Whether the account is presently held by some other device.
     *
     * Asked at sign-in, by a device that does not yet hold anything.
     */
    public function openElsewhere(User $user, Request $request): bool
    {
        $claimed = $user->active_session_token;

        if (! is_string($claimed) || $claimed === '') {
            return false;
        }

        return ! hash_equals($claimed, $this->tokenHeldBy($request));
    }

    /**
     * Whether this session's hold on the account has since ended.
     *
     * Asked on every request by a device that believes it is signed in.
     */
    public function displaced(User $user, Request $request): bool
    {
        $held = $this->tokenHeldBy($request);

        // A session carrying no token at all signed in before this rule
        // existed. It is left alone rather than thrown out over a device it
        // never had.
        if ($held === '') {
            return false;
        }

        $claimed = $user->active_session_token;

        // The account is open nowhere, yet this device thinks it is the one
        // holding it: the slot was dropped out from under it by a sign-in
        // attempt somewhere else.
        if (! is_string($claimed) || $claimed === '') {
            return true;
        }

        return ! hash_equals($claimed, $held);
    }

    /**
     * Close the account on every device at once, and say where the device that
     * caused it goes now.
     *
     * The device holding the slot loses it and is signed out by
     * {@see EnsureSingleActiveSession} on its next request; the device calling
     * this — the one that just tried to sign in — is turned back here and now,
     * mid-sign-in, before it was ever let through. Both meet again on the
     * login page, each reading the half of the story that is theirs.
     */
    public function closeEverywhere(User $user, Request $request): RedirectResponse
    {
        Log::notice('HRMS sign-in refused: the account was already open on another device.', [
            'event' => 'session.collision',
            'user_id' => $user->getKey(),
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        $user->forceFill(['active_session_token' => null])->save();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with(
            SessionNotice::FLASH_KEY,
            SessionNotice::AlreadyOpenElsewhere->value,
        );
    }

    /**
     * Give up the slot on sign-out, but only if this device still holds it —
     * a device whose hold already ended must not clear the claim of whoever
     * signed in after it.
     */
    public function release(User $user, Request $request): void
    {
        if ($this->displaced($user, $request)) {
            return;
        }

        $user->forceFill(['active_session_token' => null])->save();
    }

    private function tokenHeldBy(Request $request): string
    {
        return (string) $request->session()->get(self::SESSION_KEY, '');
    }
}
