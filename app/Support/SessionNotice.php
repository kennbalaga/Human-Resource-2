<?php

namespace App\Support;

/**
 * Why someone is looking at the login page instead of their dashboard.
 *
 * An account is held to one device at a time, and a second device arriving
 * ends the sitting for both of them rather than handing the account over: the
 * device that was already working is signed out, and the one that just tried
 * to sign in is turned away. Each side is owed its own explanation, and each
 * is shown it twice — as a dialog the moment it happens, and again on the
 * login page it lands on, where the dialog has been dismissed and the reason
 * still needs to be readable.
 */
enum SessionNotice: string
{
    /** The flashed key a redirect to the login page carries one of these in. */
    public const FLASH_KEY = 'session_notice';

    /** Read by the device that was already signed in when another one arrived. */
    case SignedInElsewhere = 'signed_in_elsewhere';

    /** Read by the device that arrived to find the account already open. */
    case AlreadyOpenElsewhere = 'already_open_elsewhere';

    /**
     * Read by a device whose account was closed while it was signed in — HR
     * terminated the employment, or archived the record. Distinct from an idle
     * timeout because signing in again will not fix it, and telling somebody
     * to sign in again when they cannot is the worst of the three messages.
     */
    case AccountClosed = 'account_closed';

    /**
     * Resolve a reason carried in a query string, ignoring anything that is
     * not one of ours — the login page must not echo a stranger's text back
     * to whoever was sent there.
     */
    public static function fromRequestValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function title(): string
    {
        return match ($this) {
            self::SignedInElsewhere => 'Your account was opened on another device',
            self::AlreadyOpenElsewhere => 'This account is already open on another device',
            self::AccountClosed => 'This account is no longer active',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::SignedInElsewhere => 'Someone signed in to this account somewhere else. Only one device can use it at a time, so this session was ended and that sign-in was stopped as well.',
            self::AlreadyOpenElsewhere => 'Someone is already signed in to this account on another device. Only one device can use it at a time, so that session was ended and this sign-in was stopped as well. Sign in again to continue on this device.',
            self::AccountClosed => 'This account has been closed and can no longer be used to sign in. Contact HR if you believe this is a mistake.',
        };
    }
}
