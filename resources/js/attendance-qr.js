// The badge on My Profile is drawn by the server, so nothing here encodes a QR
// code — this file only reads them from a camera, on the one page that does.
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** How long a result stays up before the panel returns to "Ready to scan". */
const RESULT_HOLD_MS = 8000;

/** Scans kept in "Scans this session"; the audit trail is the database, not this list. */
const SESSION_LIMIT = 50;

/**
 * Camera failures all surface as one rejected promise, and the operator at the
 * door can only act on the difference: a permission they can grant, a camera
 * another app is holding, or hardware that isn't there.
 */
const cameraFailure = (error) => {
    switch (error?.name) {
        case 'NotAllowedError':
            return {
                title: 'Camera access is blocked',
                message: 'Allow the camera in the browser’s site settings — and on a Mac, in System Settings › Privacy & Security › Camera — then try again.',
            };
        case 'NotReadableError':
        case 'AbortError':
            return { title: 'The camera is in use', message: 'Another app is holding the camera. Close it and try again.' };
        case 'NotFoundError':
            return { title: 'No camera found', message: 'This device has no camera the browser can use.' };
        default:
            return { title: 'The camera could not be opened', message: 'Try again, or use a different device.' };
    }
};

/**
 * The entrance scanner. Holds the camera open and reads frames continuously;
 * whichever badge lands in front of the lens is the person whose attendance is
 * written, which is why the view only exists for staff allowed to record it.
 *
 * Every outcome gets its own card — recorded, already scanned, or refused — so
 * an officer with a queue in front of them can tell at a glance which of the
 * three just happened, and the card clears itself for the next badge.
 */
const startScanner = () => {
    const scanner = document.querySelector('[data-qr-scanner]');
    if (!scanner) return;

    const find = (selector) => scanner.querySelector(selector);

    // The decoder is the largest thing this page can pull, and most visits to
    // the attendance page never open the camera at all, so it waits for the
    // operator to actually ask for it.
    let jsQR = null;
    const video = find('[data-scanner-video]');
    const frame = document.createElement('canvas');
    const context = frame.getContext('2d', { willReadFrequently: true });
    const viewport = find('[data-scanner-viewport]');
    const idle = find('[data-scanner-idle]');
    const idleTitle = find('[data-idle-title]');
    const idleMessage = find('[data-idle-message]');
    const startButton = find('[data-scanner-start]');
    const startLabel = find('[data-start-label]');
    const stopButton = find('[data-scanner-stop]');
    const liveBadge = find('[data-scanner-live]');
    const result = find('[data-scanner-result]');
    const sessionList = find('[data-session-list]');
    const sessionEmpty = find('[data-session-empty]');
    const sessionCount = find('[data-session-count]');
    const sessionKey = scanner.dataset.sessionKey ?? 'attendance.scanner';

    let stream = null;
    let scanning = false;
    let inFlight = false;
    // The last code accepted, held only so a badge resting in front of the lens
    // is not read as a fresh scan on every frame.
    let lastPayload = null;
    let lastPayloadAt = 0;
    let resetTimer = null;
    let flashTimer = null;

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;

        return node;
    };

    const icon = (name) => {
        const template = scanner.querySelector(`template[data-scanner-icon="${name}"]`);

        return template?.content.firstElementChild?.cloneNode(true) ?? document.createTextNode('');
    };

    const badge = ({ tone, label }) => {
        const node = element('span', `status-badge status-${tone}`);
        node.append(element('span', 'status-dot'), document.createTextNode(label));

        return node;
    };

    const punchStatus = (data) => {
        if (data.action === 'check-out') {
            return { tone: 'success', label: data.worked_hours ? `Worked ${data.worked_hours}` : 'Timed out' };
        }

        return data.status === 'late'
            ? { tone: 'warning', label: data.late_minutes ? `Late ${data.late_minutes} min` : 'Late' }
            : { tone: 'success', label: 'On time' };
    };

    const flash = (tone) => {
        window.clearTimeout(flashTimer);
        viewport.dataset.flash = tone;
        flashTimer = window.setTimeout(() => delete viewport.dataset.flash, 900);
    };

    /* ---- Result card -------------------------------------------------- */

    let showReady;

    const paint = (tone, children, { hold = false } = {}) => {
        window.clearTimeout(resetTimer);
        result.className = `panel attendance-scan-result${tone ? ` is-${tone}` : ''}`;
        result.replaceChildren(...children);

        if (hold) {
            const timer = element('span', 'attendance-scan-timer');
            timer.setAttribute('aria-hidden', 'true');
            timer.style.animationDuration = `${RESULT_HOLD_MS}ms`;
            result.append(timer);
            resetTimer = window.setTimeout(() => showReady(), RESULT_HOLD_MS);
        }
    };

    const heading = (iconName, text) => {
        const node = element('p', 'attendance-scan-heading');
        node.append(icon(iconName), element('span', null, text));

        return node;
    };

    const nextButton = (label = 'Scan next') => {
        const actions = element('div', 'attendance-scan-actions');
        const button = element('button', 'btn btn-primary');
        button.type = 'button';
        button.append(icon('scan'), element('span', null, label));
        button.addEventListener('click', () => showReady());
        actions.append(button);

        return actions;
    };

    const employeeBlock = (employee) => {
        const wrap = element('div', 'attendance-scan-employee');
        const copy = element('div');
        copy.append(
            element('strong', null, employee.name),
            element('small', null, [employee.employee_number, employee.department].filter(Boolean).join(' · ')),
        );
        wrap.append(element('span', 'attendance-scan-avatar', employee.initials), copy);

        return wrap;
    };

    showReady = () => {
        paint(null, scanning
            ? [
                heading('scan', 'Ready to scan'),
                element('p', 'attendance-scan-hint', 'Hold the badge 15–30 cm from the camera. The result appears here.'),
                element('p', 'attendance-scan-hint', 'The first scan of the day records a time in; the next records a time out.'),
            ]
            : [
                heading('camera-off', 'Scanner is paused'),
                element('p', 'attendance-scan-hint', 'Start the camera to scan badges. Each result appears here.'),
            ]);
    };

    const showRecorded = (data) => {
        const checkIn = data.action === 'check-in';
        const punch = element('p', 'attendance-scan-punch');
        punch.append(
            element('span', null, checkIn ? 'Time in' : 'Time out'),
            element('span', 'attendance-scan-time', data.recorded_at),
            badge(punchStatus(data)),
        );

        paint('success', [
            heading('check-circle', checkIn ? 'Time in recorded' : 'Time out recorded'),
            employeeBlock(data.employee),
            punch,
            nextButton(),
        ], { hold: true });
        flash('success');
    };

    // A repeat is not a fresh outcome, so it gets its own wording and, more
    // usefully, the exact moment a real scan will go through — the operator
    // otherwise has no way to tell "already recorded" from "stuck".
    const showRepeat = (data) => {
        const direction = data.action === 'check-in' ? 'in' : 'out';

        paint('warning', [
            heading('alert', 'Already scanned'),
            employeeBlock(data.employee),
            element('p', 'attendance-scan-message', `${data.employee.name}’s time ${direction} was already recorded at ${data.recorded_at}. No new record was created.`),
            element('p', 'attendance-scan-hint', data.retry_at
                ? `If this is a new event, scan the badge again after ${data.retry_at}.`
                : 'The badge was scanned again too soon.'),
            nextButton(),
        ], { hold: true });
        flash('warning');
    };

    const showRejected = (message, hint = null) => {
        paint('danger', [
            heading('x-circle', 'Not recorded'),
            element('p', 'attendance-scan-message', message),
            ...(hint ? [element('p', 'attendance-scan-hint', hint)] : []),
            nextButton('Scan again'),
        ], { hold: true });
        flash('danger');
    };

    /* ---- Session list ------------------------------------------------- */

    const readSession = () => {
        try {
            const saved = JSON.parse(window.sessionStorage.getItem(sessionKey) ?? '[]');

            return Array.isArray(saved) ? saved : [];
        } catch {
            return [];
        }
    };

    let session = readSession();

    const renderSession = (highlightNewest = false) => {
        sessionList.replaceChildren(...session.map((entry, index) => {
            const row = element('li', `attendance-session-row${highlightNewest && index === 0 ? ' is-new' : ''}`);

            const who = element('span', 'attendance-session-who');
            const copy = element('span');
            copy.append(element('strong', null, entry.name), element('small', null, entry.number ?? ''));
            who.append(element('span', 'attendance-scan-avatar', entry.initials), copy);

            const direction = element('span', 'attendance-session-direction');
            direction.append(
                icon(entry.action === 'check-in' ? 'log-in' : 'log-out'),
                element('span', null, entry.action === 'check-in' ? 'In' : 'Out'),
            );

            row.append(element('span', 'attendance-session-time', entry.time), who, direction, badge(entry));

            return row;
        }));

        sessionEmpty.hidden = session.length > 0;
        sessionCount.textContent = `${session.length} recorded`;
    };

    const remember = (data) => {
        session = [{
            time: data.recorded_at,
            name: data.employee.name,
            number: data.employee.employee_number,
            initials: data.employee.initials,
            action: data.action,
            ...punchStatus(data),
        }, ...session].slice(0, SESSION_LIMIT);

        try {
            window.sessionStorage.setItem(sessionKey, JSON.stringify(session));
        } catch {
            // A private window can refuse storage; the list still works until reload.
        }

        renderSession(true);
    };

    /* ---- Scanning ----------------------------------------------------- */

    const submit = async (payload) => {
        inFlight = true;
        paint(null, [heading('scan', 'Reading badge…')]);

        try {
            const response = await fetch(scanner.dataset.scanUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ payload }),
            });
            const body = await response.json().catch(() => null);

            if (!response.ok) {
                const message = body?.errors
                    ? Object.values(body.errors).flat()[0]
                    : (body?.message ?? 'This scan could not be recorded.');

                // A finished day names the employee already; only an unreadable
                // or retired badge needs telling what to check.
                showRejected(
                    message,
                    /already completed/i.test(message)
                        ? null
                        : 'Check that this is the employee’s hospital ID badge. A lost or reissued badge needs a new one from HR.',
                );

                return;
            }

            if (body.repeat) {
                showRepeat(body);

                return;
            }

            showRecorded(body);
            remember(body);
        } catch {
            showRejected('The scanner could not reach the server. Check the connection and try again.');
        } finally {
            inFlight = false;
        }
    };

    const readFrame = () => {
        if (!scanning) return;

        if (!inFlight && video.readyState === video.HAVE_ENOUGH_DATA) {
            frame.width = video.videoWidth;
            frame.height = video.videoHeight;
            context.drawImage(video, 0, 0, frame.width, frame.height);

            const image = context.getImageData(0, 0, frame.width, frame.height);
            const found = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
            const now = Date.now();

            if (found?.data && (found.data !== lastPayload || now - lastPayloadAt > 4000)) {
                lastPayload = found.data;
                lastPayloadAt = now;
                submit(found.data);
            }
        }

        requestAnimationFrame(readFrame);
    };

    /* ---- Camera ------------------------------------------------------- */

    const showIdle = ({ title, message, button = 'Start scanning', error = false, busy = false }) => {
        idle.hidden = false;
        idle.classList.toggle('is-error', error);
        idleTitle.textContent = title;
        idleMessage.textContent = message;
        startLabel.textContent = button;
        startButton.disabled = busy;
        liveBadge.hidden = true;
        stopButton.hidden = true;
        viewport.classList.remove('is-live');
    };

    const showLive = () => {
        idle.hidden = true;
        liveBadge.hidden = false;
        stopButton.hidden = false;
        viewport.classList.add('is-live');
    };

    const stop = () => {
        scanning = false;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
        showIdle({ title: 'Camera is off', message: 'Start the camera, then hold an employee’s badge inside the frame.' });
        showReady();
    };

    startButton.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            // Browsers withhold the camera API entirely from insecure origins,
            // which is the usual reason it is missing — worth naming, because
            // "no camera" sends people looking at the hardware instead.
            showIdle(window.isSecureContext === false
                ? { title: 'The camera needs a secure connection', message: 'Open this page as https://…, or as localhost on the machine itself.', button: 'Try again', error: true }
                : { title: 'This browser cannot open a camera', message: 'Try a current version of Chrome, Edge, Safari or Firefox.', button: 'Try again', error: true });

            return;
        }

        showIdle({ title: 'Starting the camera…', message: 'Allow camera access if the browser asks.', busy: true });

        try {
            jsQR ??= (await import('jsqr')).default;
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
                audio: false,
            });
        } catch (error) {
            // A laptop has no rear camera, and asking for one can be refused
            // outright rather than substituted; the front camera reads a badge
            // held up to it perfectly well.
            if (!['OverconstrainedError', 'NotFoundError'].includes(error?.name)) {
                showIdle({ ...cameraFailure(error), button: 'Try again', error: true });

                return;
            }

            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            } catch (fallbackError) {
                showIdle({ ...cameraFailure(fallbackError), button: 'Try again', error: true });

                return;
            }
        }

        video.srcObject = stream;
        await video.play();
        scanning = true;
        showLive();
        showReady();
        requestAnimationFrame(readFrame);
    });

    stopButton.addEventListener('click', stop);
    // A camera left running behind a closed tab is both a battery drain and a
    // recording light nobody asked for.
    window.addEventListener('pagehide', stop);

    renderSession();
    showReady();
};

document.addEventListener('DOMContentLoaded', startScanner);
