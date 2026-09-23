/**
 * The two mobile rules the server cannot enforce on its own.
 *
 * ---- 1. The roles that may not use a phone ----
 *
 * An HR manager's session reaches every employee record in the hospital and a
 * system administrator's reaches the audit log, so those accounts are desk-bound:
 * refused at sign-in by LoginRequest and turned out mid-session by
 * RestrictMobileAccessByRole. Both read the user agent, and the user agent has one
 * blind spot that matters — since iPadOS 13, Safari asks for the desktop site by
 * default and sends a string byte-for-byte identical to a Mac's. No header
 * distinguishes them. The browser can see the pointer type, so the browser is
 * where an iPad is caught, and this is that check.
 *
 * ---- 2. The phone that no longer needs the sign-in code ----
 *
 * Once a handset has an app lock, a PIN or fingerprint stands in front of the app
 * on every launch, and the authenticator the code would be read out of is on that
 * same locked handset. Asking for both is asking for one factor twice. So the
 * server is told — once, from inside an authenticated session — that this device
 * has a lock, and hands back a token to keep beside it. The next sign-in offers
 * the token and skips the code.
 *
 * What is sent is that one bit of fact and nothing else. The PIN digest, its salt
 * and the WebAuthn credential id stay in localStorage where app-lock.js put them;
 * none of them is read here, and the token means only "a lock was set up on this
 * device", which is why removing the lock withdraws it in the same breath.
 */

import { isMobileDevice } from './mobile-device';

/** app-lock.js owns this key. It is read here, never written. */
const LOCK_KEY = 'hrms.device-lock.v1';

/** This module's own key: the tokens the server handed this device. */
const TRUST_KEY = 'hrms.mobile-trust.v1';

/* ------------------------------------------------------------------ *
 * Storage
 * ------------------------------------------------------------------ */

/**
 * Every access is total. localStorage throws rather than returning null in
 * Safari's private browsing, in a locked-down managed profile, and when another
 * origin has filled the quota — and none of those is worth a broken page. A
 * device that cannot read its own storage simply has no trust and types the code,
 * which is the safe direction to fail in.
 */
const readJson = (key) => {
    try {
        const value = JSON.parse(localStorage.getItem(key) ?? '{}');

        return value && typeof value === 'object' ? value : {};
    } catch (error) {
        return {};
    }
};

const writeTrust = (map) => {
    try {
        localStorage.setItem(TRUST_KEY, JSON.stringify(map));
    } catch (error) {
        // Unstorable means untrusted, which only costs an authenticator code.
    }
};

/** Whether app-lock.js has a usable lock record for this account on this device. */
const hasAppLock = (userKey) => {
    const record = readJson(LOCK_KEY)[String(userKey)];

    return Boolean(record && typeof record === 'object' && record.hash && record.salt);
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/* ------------------------------------------------------------------ *
 * 1. The roles that may not use a phone
 * ------------------------------------------------------------------ */

/**
 * Sign this session out and land on the page that explains why.
 *
 * A generated form rather than fetch(): this is the last thing this page does, the
 * response is a redirect meant to be followed, and a form post follows it without
 * any of the code a fetch would need to navigate afterwards.
 */
const leaveForARestrictedRole = () => {
    /*
     * Hidden first. The POST is a round trip, and the whole point of the rule is
     * that the records behind it are not read on a handset — leaving the dashboard
     * legible for those few hundred milliseconds, and in the app-switcher
     * thumbnail the OS takes from it, would concede exactly that.
     *
     * Its own flag rather than the app lock's. app-lock.js clears
     * data-app-lock-pending on every path it can take, and one of those runs after
     * an await — so borrowing it would mean the shell being revealed again while
     * this request was still in flight.
     */
    document.documentElement.dataset.mobileBlocked = 'true';

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = document.querySelector('meta[name="mobile-unavailable-url"]')?.content ?? '';
    form.hidden = true;

    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = csrfToken();

    form.append(token);
    document.body.append(form);

    // The leave-site confirmation exists for a half-filled form, and this is not
    // somebody leaving by choice.
    window.markIntentionalNavigation?.();
    form.submit();
};

/* ------------------------------------------------------------------ *
 * 2. The trusted device
 * ------------------------------------------------------------------ */

const armTrust = async (userKey) => {
    const response = await fetch(document.querySelector('meta[name="trusted-mobile-url"]')?.content ?? '', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        // Nothing is sent. The server already knows who is asking, from the
        // session, and which device is asking, from the year-long device cookie.
        // There is no third thing it needs and nothing about the PIN to tell it.
        body: null,
    });

    if (!response.ok) {
        return;
    }

    const { token } = await response.json();

    if (typeof token !== 'string' || token === '') {
        return;
    }

    writeTrust({ ...readJson(TRUST_KEY), [String(userKey)]: token });
};

const revokeTrust = async (userKey) => {
    const map = readJson(TRUST_KEY);

    // The local copy goes first and unconditionally. A network that is down must
    // not leave this device believing it is still trusted — and the server-side
    // row is worthless without the token anyway.
    delete map[String(userKey)];
    writeTrust(map);

    try {
        await fetch(document.querySelector('meta[name="trusted-mobile-url"]')?.content ?? '', {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });
    } catch (error) {
        // Left standing server-side until the next sync, and unusable meanwhile.
    }
};

/**
 * Bring the server's view of this device into line with what is actually on it.
 *
 * Called on load and again whenever app-lock.js reports a change, so the two
 * cannot drift: setting a PIN arms the trust, removing it withdraws it, and a
 * device whose storage was cleared arrives here with no lock and no token and
 * asks for nothing.
 *
 * The lock-but-no-token case covers the handsets that had a PIN before this
 * feature existed, and the token-but-no-lock case covers a lock removed while the
 * network was down.
 */
const syncTrust = async (userKey) => {
    const locked = hasAppLock(userKey);
    const trusted = typeof readJson(TRUST_KEY)[String(userKey)] === 'string';

    if (locked && !trusted) {
        await armTrust(userKey);
    } else if (!locked && trusted) {
        await revokeTrust(userKey);
    }
};

/* ------------------------------------------------------------------ *
 * 3. The sign-in form
 * ------------------------------------------------------------------ */

/**
 * Offer this device's tokens with the password.
 *
 * All of them, because the form does not know which account is about to be named:
 * a shared ward handset may hold one token per member of staff who set a PIN on
 * it. Only a token belonging to the account being signed in can match, so the
 * others give nothing away — and the server caps and ignores the rest.
 */
const offerTrustAtSignIn = (field) => {
    const tokens = Object.values(readJson(TRUST_KEY)).filter((token) => typeof token === 'string' && token !== '');

    if (tokens.length > 0) {
        field.value = JSON.stringify(tokens);
    }
};

/* ------------------------------------------------------------------ *
 * Boot
 * ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', () => {
    const signInField = document.querySelector('[data-mobile-trust-field]');

    if (signInField) {
        offerTrustAtSignIn(signInField);

        // The sign-in page is not an authenticated page; there is no session to
        // restrict and no lock to sync.
        return;
    }

    // Both remaining rules are about phones. A desktop has no app lock to trade
    // and is not the device either role is being kept off.
    if (!isMobileDevice()) {
        return;
    }

    if (document.querySelector('meta[name="mobile-restricted"]')) {
        leaveForARestrictedRole();

        return;
    }

    const userKey = document.querySelector('[data-app-lock]')?.dataset.userKey;

    if (!userKey) {
        return;
    }

    // Failures are silent on purpose: the cost of an unsynced trust is an
    // authenticator code at the next sign-in, and an error toast over somebody's
    // dashboard about a device-trust round trip they never asked for is worse.
    syncTrust(userKey).catch(() => {});
    window.addEventListener('hrms:device-lock-changed', () => {
        syncTrust(userKey).catch(() => {});
    });
});
