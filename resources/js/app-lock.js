/**
 * Device app lock: a 6-digit PIN, with an optional fingerprint, on phones and
 * tablets only.
 *
 * ---- What this is, and what it deliberately is not ----
 *
 * This is a *device* lock layered on top of the app's existing server-side
 * session, not a replacement for it. The server still decides who you are, the
 * 30-minute session lifetime still applies, and two-factor authentication is
 * still the thing that protects the account. What this protects is the gap the
 * server cannot see: an unlocked, already-signed-in phone left face-up on a
 * ward desk, or handed to a colleague to make a call. Everything below is
 * enforced in the browser, so it is exactly as strong as the device it runs on
 * — which is the correct strength for the threat it addresses, and no more.
 *
 * ---- Why nothing is stored on the server ----
 *
 * A fingerprint is sensitive personal information under the Data Privacy Act,
 * and the safest way to handle sensitive personal information is to never
 * become its custodian. So:
 *
 *   - The PIN is never transmitted. What is written to this device's
 *     localStorage is a PBKDF2-SHA-256 digest over a per-device random salt.
 *     The digest cannot be replayed against the server because the server has
 *     never heard of it.
 *   - The fingerprint is never seen by this code at all. WebAuthn's platform
 *     authenticator does the matching inside the phone's secure enclave and
 *     returns a signed assertion; the only thing stored here is the credential
 *     ID needed to ask the same question again.
 *   - Clearing site data, removing the lock, or uninstalling the PWA destroys
 *     both. There is no server copy to recover from, which is the point.
 *
 * ---- The rate limit ----
 *
 * Six digits is a million combinations, and an attacker holding the unlocked
 * device can read the salt and digest out of localStorage and grind them
 * offline. PBKDF2 at 210k iterations makes that expensive rather than
 * impossible. The escalating cooldown and the wipe-after-ten-failures below
 * are what handle the realistic case — someone guessing by hand on the device
 * itself — and the wipe deliberately signs the session out too, so a wiped
 * lock cannot be used as a way to reach an unlocked app.
 */

// "Mobile devices only" is shared with the trusted-device sync and the role
// restriction, which must draw the same line this does.
import { isMobileDevice } from './mobile-device';
import { confirmAction } from './confirm-actions';

const STORAGE_KEY = 'hrms.device-lock.v1';
const SESSION_KEY = 'hrms.device-lock.session.v1';
const PIN_LENGTH = 6;
const PBKDF2_ITERATIONS = 210000;
const DEFAULT_AUTO_LOCK_SECONDS = 60;
const SOFT_FAIL_THRESHOLD = 5;
const HARD_FAIL_THRESHOLD = 10;

/* ------------------------------------------------------------------ *
 * Byte and storage helpers
 * ------------------------------------------------------------------ */

const toBase64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));

const fromBase64 = (value) => Uint8Array.from(atob(value), (character) => character.charCodeAt(0));

const randomBytes = (length) => crypto.getRandomValues(new Uint8Array(length));

/**
 * Compares two digests without an early return. A length-dependent early exit
 * leaks how many leading bytes matched, which is a real (if slow) oracle when
 * the attacker can call this as often as they like.
 */
const timingSafeEqual = (a, b) => {
    if (a.length !== b.length) {
        return false;
    }

    let difference = 0;

    for (let index = 0; index < a.length; index += 1) {
        difference |= a[index] ^ b[index];
    }

    return difference === 0;
};

const derivePinDigest = async (pin, salt, iterations) => {
    const keyMaterial = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(pin),
        'PBKDF2',
        false,
        ['deriveBits'],
    );

    const bits = await crypto.subtle.deriveBits(
        { name: 'PBKDF2', salt, iterations, hash: 'SHA-256' },
        keyMaterial,
        256,
    );

    return new Uint8Array(bits);
};

/**
 * localStorage throws rather than returning null in a few real situations —
 * Safari private browsing, a locked-down managed profile, a quota that a
 * different origin filled. None of them are worth a broken page, so every
 * access is total.
 */
const readVault = () => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') ?? {};
    } catch (error) {
        return {};
    }
};

const writeVault = (vault) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(vault));

        return true;
    } catch (error) {
        return false;
    }
};

/* ------------------------------------------------------------------ *
 * Device capability
 * ------------------------------------------------------------------ */

/** WebCrypto's subtle API is only exposed in a secure context, so this is both checks at once. */
const hasCryptoSupport = () => window.isSecureContext && typeof crypto?.subtle?.deriveBits === 'function';

const hasPlatformAuthenticator = async () => {
    if (typeof window.PublicKeyCredential?.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') {
        return false;
    }

    try {
        return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch (error) {
        return false;
    }
};

/* ------------------------------------------------------------------ *
 * The stored record
 * ------------------------------------------------------------------ */

class LockStore {
    constructor(userKey) {
        this.userKey = String(userKey);
    }

    read() {
        const record = readVault()[this.userKey];

        return record && typeof record === 'object' && record.hash && record.salt ? record : null;
    }

    write(record) {
        const vault = readVault();
        vault[this.userKey] = record;

        return writeVault(vault);
    }

    patch(changes) {
        const record = this.read();

        if (!record) {
            return null;
        }

        const next = { ...record, ...changes };
        this.write(next);

        return next;
    }

    clear() {
        const vault = readVault();
        delete vault[this.userKey];
        writeVault(vault);
    }

    /**
     * The unlocked flag lives in sessionStorage, not localStorage, precisely
     * because sessionStorage dies with the tab. A fresh launch of the installed
     * app is therefore always locked, which is the behaviour people expect from
     * every other app on their phone.
     */
    markUnlocked() {
        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify({ user: this.userKey, at: Date.now() }));
        } catch (error) {
            // A session that cannot be remembered just means unlocking again.
        }
    }

    clearUnlocked() {
        try {
            sessionStorage.removeItem(SESSION_KEY);
        } catch (error) {
            // Nothing to do; the lock screen is shown either way.
        }
    }

    isUnlockedThisSession() {
        try {
            const state = JSON.parse(sessionStorage.getItem(SESSION_KEY) ?? 'null');

            return Boolean(state) && state.user === this.userKey;
        } catch (error) {
            return false;
        }
    }
}

/* ------------------------------------------------------------------ *
 * WebAuthn — the fingerprint half
 * ------------------------------------------------------------------ */

/**
 * Registers this device's own biometric sensor against a locally generated
 * challenge.
 *
 * There is no server-side attestation check, and there is not meant to be: the
 * assertion is not being used to prove identity to the hospital's servers (the
 * session cookie already did that). It is being used to ask the phone "is the
 * person holding you the person who set this up?" and to get a hardware-backed
 * yes or no. Sending anything to a server would mean the hospital storing a
 * biometric-linked credential, which is exactly what this design avoids.
 */
const enrollBiometric = async ({ userKey, userName, userEmail }) => {
    const credential = await navigator.credentials.create({
        publicKey: {
            challenge: randomBytes(32),
            rp: { name: 'Memorial Hospital & Sanitarium', id: window.location.hostname },
            user: {
                id: new TextEncoder().encode(`hrms-device-lock-${userKey}`),
                name: userEmail || `user-${userKey}`,
                displayName: userName || 'HRMS user',
            },
            pubKeyCredParams: [
                { type: 'public-key', alg: -7 },   // ES256 — every platform authenticator.
                { type: 'public-key', alg: -257 }, // RS256 — older Windows Hello.
            ],
            authenticatorSelection: {
                // 'platform' is the load-bearing word: it refuses a roaming
                // security key, so the credential is bound to this handset and
                // cannot travel to another device.
                authenticatorAttachment: 'platform',
                userVerification: 'required',
                residentKey: 'discouraged',
                requireResidentKey: false,
            },
            timeout: 60000,
            // Nothing consumes attestation here, and requesting it would prompt
            // some platforms for extra consent for no benefit.
            attestation: 'none',
        },
    });

    if (!credential) {
        throw new Error('No credential was created.');
    }

    return toBase64(credential.rawId);
};

const verifyBiometric = async (credentialId) => {
    const assertion = await navigator.credentials.get({
        publicKey: {
            challenge: randomBytes(32),
            allowCredentials: [
                {
                    type: 'public-key',
                    id: fromBase64(credentialId),
                    transports: ['internal'],
                },
            ],
            userVerification: 'required',
            timeout: 60000,
        },
    });

    return Boolean(assertion);
};

/* ------------------------------------------------------------------ *
 * PIN quality
 * ------------------------------------------------------------------ */

const describeWeakPin = (pin) => {
    if (!/^\d{6}$/.test(pin)) {
        return 'Enter all six digits.';
    }

    if (/^(\d)\1{5}$/.test(pin)) {
        return 'A PIN of one repeated digit is guessed almost immediately. Choose another.';
    }

    const digits = pin.split('').map(Number);
    const ascending = digits.every((digit, index) => index === 0 || digit === digits[index - 1] + 1);
    const descending = digits.every((digit, index) => index === 0 || digit === digits[index - 1] - 1);

    if (ascending || descending) {
        return 'A sequential PIN such as 123456 is among the first tried. Choose another.';
    }

    return null;
};

/* ------------------------------------------------------------------ *
 * The unlock overlay
 * ------------------------------------------------------------------ */

class LockScreen {
    constructor(element, store) {
        this.element = element;
        this.store = store;
        this.buffer = '';
        this.busy = false;
        this.visible = false;

        this.dots = Array.from(element.querySelectorAll('.app-lock-dot'));
        this.dotsGroup = element.querySelector('[data-app-lock-dots]');
        this.message = element.querySelector('[data-app-lock-message]');
        this.subtitle = element.querySelector('[data-app-lock-subtitle]');
        this.keypad = element.querySelector('[data-app-lock-keypad]');
        this.biometricKey = element.querySelector('[data-app-lock-biometric]');
        this.signOutButton = element.querySelector('[data-app-lock-signout]');
        this.shell = document.querySelector('.app-shell');

        this.bind();
    }

    bind() {
        this.keypad?.addEventListener('click', (event) => {
            const key = event.target.closest('.app-lock-key');

            if (!key || this.busy) {
                return;
            }

            if (key.dataset.action === 'delete') {
                this.pop();
            } else if (key.dataset.action === 'biometric') {
                this.attemptBiometric();
            } else if (key.dataset.key) {
                this.push(key.dataset.key);
            }
        });

        this.signOutButton?.addEventListener('click', () => this.signOut());

        // Tablets with a keyboard case, and anyone who would rather type.
        document.addEventListener('keydown', (event) => {
            if (!this.visible || this.busy || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            if (/^\d$/.test(event.key)) {
                event.preventDefault();
                this.push(event.key);
            } else if (event.key === 'Backspace') {
                event.preventDefault();
                this.pop();
            }
        });

        /*
         * The overlay is a modal dialog that is not a <dialog>, so focus has to
         * be kept inside it by hand. Without this, Tab walks straight into the
         * page behind — which is still fully rendered — and a locked app is one
         * keypress away from being read.
         */
        document.addEventListener('focusin', (event) => {
            if (this.visible && !this.element.contains(event.target)) {
                this.element.querySelector('.app-lock-key')?.focus();
            }
        });
    }

    async show(reason = '') {
        if (this.visible) {
            return;
        }

        this.visible = true;
        this.buffer = '';
        this.renderDots();
        this.element.hidden = false;
        this.element.dataset.state = 'visible';
        document.body.classList.add('app-locked');

        // The overlay is now covering the shell, so the pre-paint hide has done
        // its job and can be released. Leaving it on would blank the app behind
        // the lock and show a bare colour for a frame after unlocking.
        delete document.documentElement.dataset.appLockPending;

        // Hide the app from assistive technology while it is covered, and from
        // the tab order via inert where the browser supports it.
        this.shell?.setAttribute('aria-hidden', 'true');

        if (this.shell && 'inert' in HTMLElement.prototype) {
            this.shell.inert = true;
        }

        const record = this.store.read();
        const remaining = this.cooldownRemaining(record);

        if (remaining > 0) {
            this.startCooldown(remaining);
        } else {
            this.setMessage(reason, reason ? 'info' : null);
        }

        // Offer the sensor immediately: the whole value of the fingerprint
        // option is not having to do anything, and a prompt the user dismisses
        // still leaves the keypad right there.
        if (record?.credentialId && remaining <= 0) {
            this.biometricKey.hidden = false;
            await this.attemptBiometric({ silent: true });
        } else {
            this.biometricKey.hidden = true;
        }
    }

    hide() {
        this.visible = false;
        this.element.dataset.state = 'hidden';
        this.element.hidden = true;
        document.body.classList.remove('app-locked');
        this.shell?.removeAttribute('aria-hidden');

        if (this.shell && 'inert' in HTMLElement.prototype) {
            this.shell.inert = false;
        }

        window.clearInterval(this.cooldownTimer);
    }

    renderDots() {
        this.dots.forEach((dot, index) => {
            dot.dataset.filled = String(index < this.buffer.length);
        });
    }

    setMessage(text, tone = null) {
        this.message.textContent = text ?? '';

        if (tone) {
            this.message.dataset.tone = tone;
        } else {
            delete this.message.dataset.tone;
        }
    }

    push(digit) {
        if (this.buffer.length >= PIN_LENGTH) {
            return;
        }

        this.dotsGroup.dataset.error = 'false';
        this.setMessage('');
        this.buffer += digit;
        this.renderDots();
        this.pulse(8);

        if (this.buffer.length === PIN_LENGTH) {
            this.verify();
        }
    }

    pop() {
        if (!this.buffer.length) {
            return;
        }

        this.buffer = this.buffer.slice(0, -1);
        this.renderDots();
        this.pulse(8);
    }

    /** Haptics where the platform has them; a no-op everywhere else. */
    pulse(duration) {
        if (document.body.classList.contains('reduce-motion')) {
            return;
        }

        try {
            navigator.vibrate?.(duration);
        } catch (error) {
            // Vibration is a nicety and some managed devices refuse it.
        }
    }

    cooldownRemaining(record) {
        return record?.lockedUntil ? Math.max(0, record.lockedUntil - Date.now()) : 0;
    }

    startCooldown(milliseconds) {
        window.clearInterval(this.cooldownTimer);

        const tick = () => {
            const remaining = this.cooldownRemaining(this.store.read());

            if (remaining <= 0) {
                window.clearInterval(this.cooldownTimer);
                this.busy = false;
                this.setMessage('Try again.');

                return;
            }

            this.busy = true;
            this.setMessage(`Too many attempts. Try again in ${Math.ceil(remaining / 1000)}s.`, 'error');
        };

        tick();
        this.cooldownTimer = window.setInterval(tick, 1000);
    }

    async verify() {
        const record = this.store.read();

        if (!record) {
            this.hide();

            return;
        }

        this.busy = true;

        try {
            const digest = await derivePinDigest(
                this.buffer,
                fromBase64(record.salt),
                record.iterations ?? PBKDF2_ITERATIONS,
            );

            if (timingSafeEqual(digest, fromBase64(record.hash))) {
                this.store.patch({ failedAttempts: 0, lockedUntil: 0 });
                this.store.markUnlocked();
                this.pulse(14);
                this.hide();

                return;
            }

            await this.registerFailure(record);
        } catch (error) {
            this.setMessage('This device could not check the PIN. Sign out and back in.', 'error');
        } finally {
            this.busy = this.cooldownRemaining(this.store.read()) > 0;
            this.buffer = '';
            this.renderDots();
        }
    }

    async registerFailure(record) {
        const failedAttempts = (record.failedAttempts ?? 0) + 1;

        if (failedAttempts >= HARD_FAIL_THRESHOLD) {
            // Ten wrong guesses is not a person who forgot their own PIN. The
            // local lock is destroyed and the server session goes with it, so
            // the wipe cannot be used as a route into an unlocked app.
            this.store.clear();
            this.store.clearUnlocked();
            this.setMessage('Too many failed attempts. The app lock has been removed and you are being signed out.', 'error');
            this.pulse([20, 60, 20]);
            window.setTimeout(() => this.signOut(), 1800);

            return;
        }

        let lockedUntil = 0;

        if (failedAttempts >= SOFT_FAIL_THRESHOLD) {
            // 15s, 30s, 60s, 120s, 240s — doubling, capped at five minutes.
            const penalty = Math.min(15000 * 2 ** (failedAttempts - SOFT_FAIL_THRESHOLD), 300000);
            lockedUntil = Date.now() + penalty;
        }

        this.store.patch({ failedAttempts, lockedUntil });
        this.dotsGroup.dataset.error = 'true';
        this.pulse([12, 40, 12]);

        if (lockedUntil) {
            this.startCooldown(lockedUntil - Date.now());
        } else {
            const left = HARD_FAIL_THRESHOLD - failedAttempts;
            this.setMessage(`Incorrect PIN. ${left} ${left === 1 ? 'attempt' : 'attempts'} left.`, 'error');
        }

        window.setTimeout(() => {
            this.dotsGroup.dataset.error = 'false';
        }, 420);
    }

    async attemptBiometric({ silent = false } = {}) {
        const record = this.store.read();

        if (!record?.credentialId || this.busy) {
            return;
        }

        this.busy = true;

        if (!silent) {
            this.setMessage('Waiting for the sensor…');
        }

        try {
            if (await verifyBiometric(record.credentialId)) {
                this.store.patch({ failedAttempts: 0, lockedUntil: 0 });
                this.store.markUnlocked();
                this.pulse(14);
                this.hide();

                return;
            }
        } catch (error) {
            /*
             * Every dismissal lands here: cancelling the system sheet, a
             * fingerprint the sensor does not recognise, a device that has since
             * removed every enrolled finger. None of them is an error worth
             * shouting about — the keypad is still there — so a silent attempt
             * stays silent and only an explicit tap gets a message.
             */
            if (!silent) {
                this.setMessage('Fingerprint not recognised. Enter your PIN instead.');
            }
        } finally {
            this.busy = this.cooldownRemaining(this.store.read()) > 0;
        }
    }

    /**
     * Ends the server session too. Leaving the session alive while showing a
     * lock screen would mean "sign out" only closed the door on this tab.
     */
    signOut() {
        this.store.clearUnlocked();

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = this.element.dataset.logoutUrl;
        form.hidden = true;

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = this.element.dataset.csrf;

        form.append(token);
        document.body.append(form);
        window.markIntentionalNavigation();
        form.submit();
    }
}

/* ------------------------------------------------------------------ *
 * The settings panel
 * ------------------------------------------------------------------ */

class LockSettings {
    constructor(panel, store, context) {
        this.panel = panel;
        this.store = store;
        this.context = context;
        this.draftPin = '';

        this.steps = new Map(
            Array.from(panel.querySelectorAll('[data-device-lock-step]'))
                .map((step) => [step.dataset.deviceLockStep, step]),
        );

        this.statusBadge = panel.querySelector('[data-device-lock-status]');
        this.biometricBadge = panel.querySelector('[data-device-lock-biometric-badge]');
        this.biometricLabel = panel.querySelector('[data-device-lock-biometric-label]');
        this.biometricSummary = panel.querySelector('[data-device-lock-biometric-summary]');
        this.biometricRow = panel.querySelector('[data-device-lock-biometric-row]');
        // Both the summary row's button and the nudge's button carry
        // data-device-lock-action="toggle-biometric", so this must be the one
        // inside the row — querySelector would otherwise pick whichever comes
        // first in the document and relabel the wrong control.
        this.biometricToggle = this.biometricRow.querySelector('[data-device-lock-action="toggle-biometric"]');
        this.biometricNudge = panel.querySelector('[data-device-lock-nudge]');
        this.autoLockSelect = panel.querySelector('[data-device-lock-autolock]');

        this.bindPinFields();
        this.bindActions();
    }

    /**
     * Six painted cells over one real input. Six real inputs would mean six
     * focus targets, a soft keyboard that closes and reopens between digits,
     * and a browser offering to autofill them as a form — all of which is why
     * the visible cells are `pointer-events: none` decoration.
     */
    bindPinFields() {
        this.panel.querySelectorAll('[data-device-lock-pin]').forEach((field) => {
            const input = field.querySelector('[data-device-lock-pin-input]');
            const cells = Array.from(field.querySelectorAll('.device-lock-pin-cell'));

            const render = () => {
                const value = input.value;

                cells.forEach((cell, index) => {
                    cell.dataset.filled = String(index < value.length);
                    cell.dataset.active = String(index === Math.min(value.length, PIN_LENGTH - 1));
                    // A masked dot rather than the digit: this panel is used in
                    // corridors and lift lobbies.
                    cell.textContent = index < value.length ? '•' : '';
                });
            };

            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, PIN_LENGTH);
                field.dataset.error = 'false';
                render();
            });

            input.addEventListener('focus', () => {
                field.dataset.focused = 'true';
                render();
            });

            input.addEventListener('blur', () => {
                field.dataset.focused = 'false';
            });

            // Tapping anywhere on the row of cells focuses the hidden input.
            field.addEventListener('click', () => input.focus());

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.panel
                        .querySelector(`[data-device-lock-step="${field.dataset.deviceLockPin === 'create' ? 'create' : 'confirm'}"] .btn-primary`)
                        ?.click();
                }
            });

            render();
        });
    }

    bindActions() {
        this.panel.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-device-lock-action]');

            if (!trigger) {
                return;
            }

            const handlers = {
                start: () => this.openStep('create'),
                'continue-create': () => this.continueFromCreate(),
                back: () => this.openStep('create'),
                save: () => this.savePin(),
                cancel: () => this.render(),
                'enroll-biometric': () => this.enroll(),
                'skip-biometric': () => this.render('PIN saved. You can add a fingerprint at any time.'),
                'change-pin': () => this.openStep('create'),
                'toggle-biometric': () => this.toggleBiometric(),
                'lock-now': () => this.lockNow(),
                remove: () => this.remove(),
            };

            handlers[trigger.dataset.deviceLockAction]?.();
        });

        this.autoLockSelect?.addEventListener('change', () => {
            this.store.patch({ autoLockSeconds: Number(this.autoLockSelect.value) });
            this.feedback('enrolled', 'Auto-lock timing saved on this device.', 'success');
        });
    }

    feedback(step, text, tone = null) {
        const target = this.panel.querySelector(`[data-device-lock-feedback="${step}"]`);

        if (!target) {
            return;
        }

        target.textContent = text ?? '';

        if (tone) {
            target.dataset.tone = tone;
        } else {
            delete target.dataset.tone;
        }
    }

    pinInput(step) {
        return this.panel.querySelector(`[data-device-lock-pin="${step}"] [data-device-lock-pin-input]`);
    }

    clearPinField(step) {
        const input = this.pinInput(step);

        if (input) {
            input.value = '';
            input.dispatchEvent(new Event('input'));
        }
    }

    openStep(name, message = '') {
        this.steps.forEach((element, key) => {
            element.hidden = key !== name;
        });

        // Always offered. Starting a first-time setup and finding no way out of
        // it short of leaving the page is a dead end; `cancel` re-renders, which
        // lands on 'idle' when there is no PIN yet and 'enrolled' when there is.
        this.panel.querySelector('[data-device-lock-action="cancel"]').hidden = false;

        if (name === 'create' || name === 'confirm') {
            this.clearPinField(name);
            this.feedback(name, message);
            // A deliberate tick: focusing a field that was `hidden` a moment ago
            // is ignored by Safari.
            window.setTimeout(() => this.pinInput(name)?.focus(), 60);
        }
    }

    continueFromCreate() {
        const pin = this.pinInput('create').value;
        const problem = describeWeakPin(pin);

        if (problem) {
            this.panel.querySelector('[data-device-lock-pin="create"]').dataset.error = 'true';
            this.feedback('create', problem, 'error');

            return;
        }

        this.draftPin = pin;
        this.openStep('confirm');
    }

    async savePin() {
        const confirmation = this.pinInput('confirm').value;

        if (confirmation !== this.draftPin) {
            this.panel.querySelector('[data-device-lock-pin="confirm"]').dataset.error = 'true';
            this.clearPinField('confirm');
            this.feedback('confirm', 'The two PINs do not match. Enter it again.', 'error');

            return;
        }

        this.feedback('confirm', 'Securing your PIN on this device…');

        try {
            const salt = randomBytes(16);
            const digest = await derivePinDigest(this.draftPin, salt, PBKDF2_ITERATIONS);
            const existing = this.store.read();

            const saved = this.store.write({
                version: 1,
                salt: toBase64(salt),
                hash: toBase64(digest),
                iterations: PBKDF2_ITERATIONS,
                // Changing a PIN keeps an already-enrolled fingerprint: the
                // sensor is a separate factor and re-enrolling it would be busy
                // work with a system prompt attached.
                credentialId: existing?.credentialId ?? null,
                autoLockSeconds: existing?.autoLockSeconds ?? DEFAULT_AUTO_LOCK_SECONDS,
                createdAt: existing?.createdAt ?? Date.now(),
                updatedAt: Date.now(),
                failedAttempts: 0,
                lockedUntil: 0,
            });

            if (!saved) {
                this.feedback('confirm', 'This browser is not allowing local storage, so the PIN cannot be kept on this device.', 'error');

                return;
            }
        } catch (error) {
            this.feedback('confirm', 'This device could not secure the PIN. Try again.', 'error');

            return;
        } finally {
            // The PIN itself is never held longer than it takes to hash it.
            this.draftPin = '';
            this.clearPinField('create');
            this.clearPinField('confirm');
        }

        this.store.markUnlocked();
        window.dispatchEvent(new CustomEvent('hrms:device-lock-changed'));

        if (this.context.biometricAvailable && !this.store.read()?.credentialId) {
            this.openStep('biometric');
            this.feedback('biometric', '');

            return;
        }

        this.render('PIN saved. This device will ask for it when the app is reopened, and in place of your authenticator code when you sign in.');
    }

    async enroll() {
        this.feedback('biometric', 'Waiting for this device\'s sensor…');

        try {
            const credentialId = await enrollBiometric(this.context);
            this.store.patch({ credentialId });
            window.dispatchEvent(new CustomEvent('hrms:device-lock-changed'));
            this.render('Fingerprint unlock is on for this device.');
        } catch (error) {
            /*
             * A cancelled system sheet and a genuinely unsupported sensor both
             * reject here, and neither is a failure the user needs to act on —
             * the PIN they just set is already working. NotAllowedError is the
             * cancellation case and gets softer wording.
             */
            this.feedback(
                'biometric',
                error?.name === 'NotAllowedError'
                    ? 'Fingerprint setup was cancelled. Your PIN is saved and you can add a fingerprint later.'
                    : 'This device would not register a fingerprint. Your PIN is saved and still works.',
            );

            window.setTimeout(() => this.render(), 2600);
        }
    }

    async toggleBiometric() {
        const record = this.store.read();

        if (!record) {
            return;
        }

        if (record.credentialId) {
            this.store.patch({ credentialId: null });
            window.dispatchEvent(new CustomEvent('hrms:device-lock-changed'));
            this.render('Fingerprint unlock turned off. Your PIN still works.');

            return;
        }

        this.openStep('biometric');
        await this.enroll();
    }

    lockNow() {
        this.store.clearUnlocked();
        window.dispatchEvent(new CustomEvent('hrms:device-lock-lock-now'));
    }

    async remove() {
        const confirmed = await confirmAction({
            title: 'Remove the app lock from this device?',
            message: 'The PIN and any fingerprint saved here are deleted, and signing in on this device will ask for your authenticator code again. Your account and password are not affected.',
            button: 'Remove app lock',
            tone: 'danger',
        });

        if (!confirmed) {
            return;
        }

        this.store.clear();
        this.store.clearUnlocked();
        window.dispatchEvent(new CustomEvent('hrms:device-lock-changed'));
        this.render('App lock removed from this device.');
    }

    render(message = '') {
        const record = this.store.read();

        this.panel.dataset.available = 'true';
        this.statusBadge.textContent = record ? 'Protected' : 'Not set up';
        this.statusBadge.dataset.state = record ? 'on' : 'off';

        this.openStep(record ? 'enrolled' : 'idle');

        if (record) {
            const hasBiometric = Boolean(record.credentialId);

            this.biometricBadge.dataset.state = hasBiometric ? 'on' : 'off';
            this.biometricLabel.textContent = hasBiometric ? 'On' : 'Off';
            this.biometricToggle.textContent = hasBiometric ? 'Turn off' : 'Turn on';
            this.biometricSummary.textContent = hasBiometric
                ? 'On for this device. The sensor answers yes or no — the fingerprint itself never reaches the app or the server.'
                : 'Optional. Uses this phone\'s own sensor; the fingerprint itself never reaches the app.';

            // The row is pointless on a device with no sensor to offer.
            this.biometricRow.hidden = !this.context.biometricAvailable && !hasBiometric;

            // The standing offer: PIN set, sensor present, fingerprint not yet
            // on. It disappears the moment it is accepted.
            this.biometricNudge.hidden = !(this.context.biometricAvailable && !hasBiometric);

            this.autoLockSelect.value = String(record.autoLockSeconds ?? DEFAULT_AUTO_LOCK_SECONDS);
        }

        if (message) {
            this.feedback(record ? 'enrolled' : 'idle', message, 'success');
        }
    }
}

/* ------------------------------------------------------------------ *
 * Boot
 * ------------------------------------------------------------------ */

/**
 * Releases the pre-paint hide set by the inline <head> script. Every exit from
 * boot has to call this, including the early returns — a path that forgets it
 * leaves the app invisible until the 4s failsafe fires.
 */
const revealShell = () => {
    delete document.documentElement.dataset.appLockPending;
};

document.addEventListener('DOMContentLoaded', async () => {
    const overlay = document.querySelector('[data-app-lock]');

    if (!overlay) {
        revealShell();

        return;
    }

    // Desktop, an insecure origin, or a browser without WebCrypto: the feature
    // is simply not offered. Nothing is hidden after the fact — the panel and
    // the overlay were already display:none and stay that way.
    if (!isMobileDevice() || !hasCryptoSupport()) {
        revealShell();

        return;
    }

    const store = new LockStore(overlay.dataset.userKey);
    const context = {
        userKey: overlay.dataset.userKey,
        userName: overlay.dataset.userName,
        userEmail: overlay.dataset.userEmail,
        biometricAvailable: await hasPlatformAuthenticator(),
    };

    const lockScreen = new LockScreen(overlay, store);

    /* ---- Settings panel, when we are on the settings page ---- */
    const panel = document.querySelector('[data-device-lock-panel]');

    if (panel) {
        new LockSettings(panel, store, context).render();
        document.querySelector('[data-device-lock-nav]')?.setAttribute('data-available', 'true');
    }

    /* ---- Lock lifecycle ---- */
    const autoLockSeconds = () => store.read()?.autoLockSeconds ?? DEFAULT_AUTO_LOCK_SECONDS;

    let hiddenAt = 0;

    const lockIfArmed = (reason) => {
        if (store.read()) {
            store.clearUnlocked();
            lockScreen.show(reason);
        }
    };

    // A fresh page load with no unlocked flag is either the app's first launch
    // or a navigation after it was locked. Both must ask.
    if (store.read() && !store.isUnlockedThisSession()) {
        lockScreen.show();
    } else {
        revealShell();
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();

            return;
        }

        if (!store.read() || lockScreen.visible) {
            return;
        }

        const grace = autoLockSeconds() * 1000;
        const away = Date.now() - hiddenAt;

        // grace === 0 means "lock the moment it leaves the screen", so any
        // return at all re-locks.
        if (hiddenAt && (grace === 0 || away >= grace)) {
            lockIfArmed();
        }
    });

    /*
     * iOS does not reliably fire visibilitychange when the app is swiped away,
     * but it does fire pagehide. Recording the time here means the elapsed
     * check still works on the next launch.
     */
    window.addEventListener('pagehide', () => {
        hiddenAt = Date.now();
    });

    window.addEventListener('hrms:device-lock-lock-now', () => lockIfArmed());

    // Turning the lock off in Settings must take the overlay's fingerprint key
    // and armed state with it, without a reload.
    window.addEventListener('hrms:device-lock-changed', () => {
        if (!store.read() && lockScreen.visible) {
            lockScreen.hide();
        }
    });
});
