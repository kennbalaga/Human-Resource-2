import { Modal } from 'bootstrap';

const element = document.querySelector('[data-session-timeout]');

if (element) {
    const timeoutMs = Number(element.dataset.timeoutSeconds) * 1000;
    const warningMs = Number(element.dataset.warningSeconds) * 1000;
    const heartbeatMs = Number(element.dataset.heartbeatSeconds) * 1000;
    const timeoutMinutes = Math.round(timeoutMs / 60000);
    const warningStartsAt = timeoutMs - warningMs;
    const activityKey = 'workforce.session.last-activity';
    const expiredKey = 'workforce.session.expired-at';
    const expiredReasonKey = 'workforce.session.expired-reason';
    const modal = Modal.getOrCreateInstance(element, { backdrop: 'static', keyboard: false });
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const title = element.querySelector('[data-session-title]');
    const kicker = element.querySelector('[data-session-kicker]');
    const message = element.querySelector('[data-session-message]');
    const countdown = element.querySelector('[data-session-countdown]');
    const countdownWrap = element.querySelector('[data-session-countdown-wrap]');
    const continueButton = element.querySelector('[data-session-continue]');
    const signOutButton = element.querySelector('[data-session-sign-out]');
    const loginButton = element.querySelector('[data-session-login]');
    let warningVisible = false;
    let expired = false;
    let expiredReason = null;
    let endingSession = false;
    let lastHeartbeatAt = Date.now();

    // The login page repeats whatever ended the session, so it has to be told.
    // Nothing survives the sign-out itself — the session the server would have
    // flashed a message into is the one being thrown away — so the reason
    // travels in the address instead.
    const loginUrl = (reason) => {
        const url = new URL(element.dataset.loginUrl, window.location.origin);

        if (reason) {
            url.searchParams.set('reason', reason);
        }

        return url.toString();
    };

    const readNumber = (key, fallback) => {
        try {
            const value = Number(localStorage.getItem(key));

            return Number.isFinite(value) && value > 0 ? value : fallback;
        } catch (error) {
            return fallback;
        }
    };

    const writeNumber = (key, value) => {
        try {
            localStorage.setItem(key, String(value));
        } catch (error) {
            // The timeout still works in this tab when storage is unavailable.
        }
    };

    // Every tab on this device loses the session together, so whichever tab
    // finds out first leaves the reason behind for the others to show.
    const writeReason = (reason) => {
        try {
            if (reason) localStorage.setItem(expiredReasonKey, reason);
            else localStorage.removeItem(expiredReasonKey);
        } catch (error) {
            // This tab still shows its own reason without storage.
        }
    };

    const readReason = () => {
        try {
            return localStorage.getItem(expiredReasonKey);
        } catch (error) {
            return null;
        }
    };

    const clearKey = (key) => {
        try {
            localStorage.removeItem(key);
        } catch (error) {
            // Nothing else is required when storage is unavailable.
        }
    };

    let lastActivityAt = Date.now();
    writeNumber(activityKey, lastActivityAt);
    clearKey(expiredKey);
    clearKey(expiredReasonKey);

    const formatDuration = (milliseconds) => {
        const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;

        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    };

    const request = async (url, options = {}) => {
        const controller = new AbortController();
        const requestTimeout = window.setTimeout(() => controller.abort(), 10000);
        let response;

        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    ...options.headers,
                },
                ...options,
                signal: controller.signal,
            });
        } finally {
            window.clearTimeout(requestTimeout);
        }

        if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
            const error = new Error('The authenticated session is no longer available.');
            // The server says why it ended when it can. Being signed out
            // because the account opened on another device reads nothing like
            // an idle timeout, and the person at this screen needs to be told
            // which of the two happened.
            error.detail = await response.json().catch(() => null);

            throw error;
        }

        return response;
    };

    const recordActivity = () => {
        if (warningVisible || expired) {
            return;
        }

        const now = Date.now();

        if (now - lastActivityAt < 1000) {
            return;
        }

        lastActivityAt = now;
        writeNumber(activityKey, now);
    };

    const showWarning = (remaining) => {
        warningVisible = true;
        kicker.textContent = 'Security reminder';
        title.textContent = 'Are you still working?';
        message.textContent = `Your session will end after ${timeoutMinutes} minutes of inactivity to protect workforce information.`;
        countdownWrap.classList.remove('d-none');
        countdown.textContent = formatDuration(remaining);
        continueButton.classList.remove('d-none');
        signOutButton.classList.remove('d-none');
        loginButton.classList.add('d-none');
        modal.show();
    };

    const showExpired = (reason = null) => {
        const displaced = reason === 'signed_in_elsewhere';
        expired = true;
        expiredReason = reason;
        warningVisible = true;
        kicker.textContent = 'Session ended';
        title.textContent = displaced
            ? 'Your account was opened on another device'
            : 'You were signed out for inactivity';
        // Not a handover: whoever tried to sign in elsewhere was turned away
        // too, so saying only that this device lost the account would leave
        // the person here expecting someone to be using it.
        message.textContent = displaced
            ? 'Someone signed in to this account somewhere else. Only one device can use it at a time, so this session was ended and that sign-in was stopped as well.'
            : 'Your saved Employee ID is ready on the login page. Enter your password and authenticator code to continue.';
        countdownWrap.classList.add('d-none');
        continueButton.classList.add('d-none');
        signOutButton.classList.add('d-none');
        loginButton.classList.remove('d-none');
        modal.show();
    };

    const endSession = async (reason = null) => {
        if (endingSession) {
            return;
        }

        endingSession = true;
        showExpired(reason);
        writeNumber(expiredKey, Date.now());
        writeReason(reason);

        try {
            await request(element.dataset.logoutUrl, { method: 'POST' });
        } catch (error) {
            // The server may already have expired the session; the login route is still safe.
        } finally {
            loginButton.disabled = false;
        }
    };

    const keepAlive = async () => {
        continueButton.disabled = true;

        try {
            await request(element.dataset.keepAliveUrl);
            const now = Date.now();
            lastActivityAt = now;
            lastHeartbeatAt = now;
            writeNumber(activityKey, now);
            clearKey(expiredKey);
            clearKey(expiredReasonKey);
            warningVisible = false;
            modal.hide();
        } catch (error) {
            await endSession(error.detail?.reason ?? null);
        } finally {
            continueButton.disabled = false;
        }
    };

    // Asking the server whether this device is still signed in is also how it
    // learns the account has been opened somewhere else, and that has to reach
    // the person watching the screen on its own — waiting for them to click
    // something would mean sitting in front of a session that already ended.
    // So this runs on a short interval rather than a lazy one, and pays for it
    // by easing off to a slow check while the tab is in the background, where
    // there is nobody to tell yet. Backgrounded is quieter, never silent: the
    // tab still finds out on its own, and finds out at once when looked at.
    const backgroundHeartbeatMs = Math.max(heartbeatMs, 60000);

    const heartbeat = async () => {
        const dueAfter = document.hidden ? backgroundHeartbeatMs : heartbeatMs;

        if (warningVisible || expired || Date.now() - lastHeartbeatAt < dueAfter) {
            return;
        }

        lastHeartbeatAt = Date.now();

        try {
            await request(element.dataset.keepAliveUrl);
        } catch (error) {
            await endSession(error.detail?.reason ?? null);
        }
    };

    const tick = () => {
        if (expired) {
            return;
        }

        const sharedActivityAt = readNumber(activityKey, lastActivityAt);
        lastActivityAt = Math.max(lastActivityAt, sharedActivityAt);
        const idleFor = Date.now() - lastActivityAt;

        if (idleFor >= timeoutMs) {
            endSession();

            return;
        }

        if (idleFor >= warningStartsAt) {
            showWarning(timeoutMs - idleFor);

            return;
        }

        heartbeat();
    };

    element.addEventListener('show.bs.modal', () => {
        if (warningVisible || expired) {
            return;
        }

        // A manual preview should exercise the real countdown and reset flow,
        // not display a static copy of the warning.
        const previewActivityAt = Date.now() - warningStartsAt;
        lastActivityAt = previewActivityAt;
        writeNumber(activityKey, previewActivityAt);
        warningVisible = true;
    });

    ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((eventName) => {
        document.addEventListener(eventName, recordActivity, { passive: true });
    });

    // A tab returning to the foreground may have been signed out from under it
    // while it sat in the background and nobody was asking. Skip the wait.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            return;
        }

        lastHeartbeatAt = 0;
        heartbeat();
    });

    window.addEventListener('storage', (event) => {
        if (event.key === expiredKey && event.newValue) {
            showExpired(readReason());
            loginButton.disabled = false;
        }
    });

    continueButton.addEventListener('click', keepAlive);
    signOutButton.addEventListener('click', async () => {
        await endSession();
        window.location.assign(element.dataset.loginUrl);
    });
    loginButton.addEventListener('click', () => window.location.assign(loginUrl(expiredReason)));

    window.setInterval(tick, 1000);
}
