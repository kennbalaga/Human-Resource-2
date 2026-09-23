<?php

namespace App\Services;

use App\Http\Middleware\EnsureSingleActiveSession;
use App\Models\User;
use App\Support\SessionNotice;
use Carbon\CarbonInterface;
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
 *
 * Two devices at once is the only thing this is defending against, so a claim
 * has to be able to end quietly as well as loudly. A session ends far more
 * often by being walked away from than by being signed out of, and the device
 * it belonged to never says so. A claim therefore stands only while the device
 * holding it keeps being heard from, and stops standing against the browser
 * that made it — coming back to the same browser is coming back, not arriving
 * second.
 */
class ActiveDeviceSessionService
{
    public const SESSION_KEY = 'auth.active_session_token';

    public function __construct(
        private readonly DeviceIdentityService $deviceIdentity,
    ) {}

    /**
     * How often a device working away re-states that it is still holding the
     * account. Often enough that a live session is never mistaken for an
     * abandoned one, rarely enough that the once-a-second heartbeat behind it
     * does not turn into a write per second.
     */
    private const HEARD_FROM_EVERY_SECONDS = 60;

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

        $user->forceFill([
            'active_session_token' => $token,
            'active_session_device_hash' => $this->deviceHash($request),
            'active_session_last_seen_at' => now(),
        ])->save();
    }

    /**
     * Whether the account is presently held by some other device.
     *
     * Asked at sign-in, by a device that does not yet hold anything.
     */
    public function openElsewhere(User $user, Request $request): bool
    {
        // A claim nobody has stood behind for longer than a session is allowed
        // to live belongs to a session that has already ended — a browser
        // closed, a screen walked away from — and holds nothing against
        // anybody.
        if (! $this->claimStillStands($user)) {
            return false;
        }

        // Nor does this browser's own claim hold anything against it. Its
        // session cookie is gone, which is the only reason it is at the login
        // page, but it is the same device and there is no second one to
        // protect the account from.
        if ($this->claimHeldByThisDevice($user, $request)) {
            return false;
        }

        return ! $this->holds($user, $request);
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
     * Say that the device holding the account is still at it.
     *
     * A claim is only as good as the last time the device behind it was heard
     * from, and this is that hearing: every request a signed-in device makes,
     * down to the keep-alive it sends while nobody is touching the keyboard.
     * Between those, the note is left as it is — the slot cannot be lost while
     * the device is speaking often enough to keep it, and a browser that goes
     * quiet is exactly the one that should stop holding the account.
     */
    public function keepHold(User $user, Request $request): void
    {
        if (! $this->holds($user, $request)) {
            return;
        }

        $seen = $user->active_session_last_seen_at;

        if ($seen instanceof CarbonInterface && $seen->greaterThan(now()->subSeconds(self::HEARD_FROM_EVERY_SECONDS))) {
            return;
        }

        // Written past the model so that being present does not read as being
        // edited: `updated_at` belongs to the account's own record, not to the
        // heartbeat of whoever is looking at it.
        User::query()
            ->whereKey($user->getKey())
            ->update(['active_session_last_seen_at' => now()]);
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

        $this->clearClaim($user);

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

        $this->clearClaim($user);
    }

    /**
     * Whether this request is coming from the device the account is recorded
     * against: it is carrying the claimed token, in its own session.
     */
    private function holds(User $user, Request $request): bool
    {
        $claimed = $user->active_session_token;

        if (! is_string($claimed) || $claimed === '') {
            return false;
        }

        return hash_equals($claimed, $this->tokenHeldBy($request));
    }

    /**
     * Whether the recorded claim is still speaking for a session that exists.
     *
     * Nothing tells this application that a session ended, so the claim is
     * read against the clock instead: a device last heard from longer ago than
     * a session may live cannot still be signed in, whatever the token says.
     */
    private function claimStillStands(User $user): bool
    {
        $claimed = $user->active_session_token;

        if (! is_string($claimed) || $claimed === '') {
            return false;
        }

        $seen = $user->active_session_last_seen_at;

        return $seen instanceof CarbonInterface
            && $seen->greaterThan(now()->subMinutes($this->sessionLifetimeMinutes()));
    }

    private function claimHeldByThisDevice(User $user, Request $request): bool
    {
        $held = $user->active_session_device_hash;

        return is_string($held) && $held !== '' && hash_equals($held, $this->deviceHash($request));
    }

    private function clearClaim(User $user): void
    {
        $user->forceFill([
            'active_session_token' => null,
            'active_session_device_hash' => null,
            'active_session_last_seen_at' => null,
        ])->save();
    }

    private function sessionLifetimeMinutes(): int
    {
        return max(1, (int) config('session.lifetime', 30));
    }

    private function tokenHeldBy(Request $request): string
    {
        return (string) $request->session()->get(self::SESSION_KEY, '');
    }

    /**
     * Delegated rather than computed here: the trusted-mobile-device rule reads
     * the same cookie, and two implementations of "which browser is this" would
     * eventually disagree about one.
     */
    private function deviceHash(Request $request): string
    {
        return $this->deviceIdentity->hash($request);
    }
}
