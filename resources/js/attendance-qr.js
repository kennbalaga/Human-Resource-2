// The encoder and the decoder are each a sizeable chunk, and every page in the
// app loads this bundle. They are pulled in only once an element that actually
// needs them is on the page.
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/**
 * Camera failures all surface as one rejected promise, and the operator at the
 * door can only act on the difference: a permission they can grant, a camera
 * another app is holding, or hardware that isn't there.
 */
const cameraFailureMessage = (error) => {
    switch (error?.name) {
        case 'NotAllowedError':
            return 'Camera access is blocked for this site. Allow the camera in the browser’s site settings — and on a Mac, in System Settings › Privacy & Security › Camera — then try again.';
        case 'NotReadableError':
        case 'AbortError':
            return 'The camera is already in use by another app. Close it and try again.';
        case 'NotFoundError':
            return 'No camera was found on this device.';
        default:
            return 'The camera could not be opened on this device.';
    }
};

/**
 * The employee's own badge, drawn on their profile. Rendered in the browser
 * from the signed payload the server issued, so the code itself is never stored
 * as an image anywhere it could be picked up.
 */
const renderProfileCode = async () => {
    const panel = document.querySelector('[data-attendance-qr]');
    const canvas = panel?.querySelector('[data-qr-canvas]');
    if (!panel || !canvas || !panel.dataset.qrPayload) return;

    try {
        const { default: QRCode } = await import('qrcode');
        await QRCode.toCanvas(canvas, panel.dataset.qrPayload, {
            width: 220,
            margin: 1,
            errorCorrectionLevel: 'M',
            color: { dark: '#101c17', light: '#ffffff' },
        });
    } catch {
        canvas.hidden = true;
        panel.querySelector('[data-qr-fallback]').hidden = false;

        return;
    }

    panel.querySelector('[data-qr-download]')?.addEventListener('click', () => {
        const link = document.createElement('a');
        link.download = panel.dataset.qrFilename || 'attendance-qr.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
};

/**
 * Pull the page again and swap in the parts a scan just changed — the personal
 * log and today's own status. A scan writes through JSON so the camera can keep
 * running, which otherwise leaves the tables below it showing the state of
 * things before the badge was read.
 *
 * The panels are re-rendered by the server rather than rebuilt here, so the
 * rows keep one definition; the scanner's own markup and the live clock are
 * deliberately left untouched, since replacing them would stop the camera and
 * the ticking clock respectively.
 */
const refreshOwnRecords = async () => {
    const targets = ['[data-attendance-live]', '.attendance-history-panel'];

    try {
        const response = await fetch(window.location.href, {
            headers: { Accept: 'text/html' },
            cache: 'no-store',
            credentials: 'same-origin',
        });
        if (!response.ok) return;

        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');

        targets.forEach((selector) => {
            const current = document.querySelector(selector);
            const replacement = fresh.querySelector(selector);
            if (current && replacement) current.replaceWith(replacement);
        });
    } catch {
        // The scan itself is already recorded; a stale table is not worth
        // interrupting the queue at the door for.
    }
};

/**
 * The entrance scanner. Holds the camera open and reads frames continuously;
 * whichever badge lands in front of the lens is the person whose attendance is
 * written, which is why the panel only exists for staff allowed to record it.
 */
const startScanner = () => {
    const scanner = document.querySelector('[data-qr-scanner]');
    if (!scanner) return;

    // The decoder is the largest thing this page can pull, and most visits to
    // the attendance page never open the camera at all, so it waits for the
    // operator to actually ask for it.
    let jsQR = null;
    const video = scanner.querySelector('[data-scanner-video]');
    const frame = document.createElement('canvas');
    const context = frame.getContext('2d', { willReadFrequently: true });
    const startButton = scanner.querySelector('[data-scanner-start]');
    const stopButton = scanner.querySelector('[data-scanner-stop]');
    const status = scanner.querySelector('[data-scanner-status]');
    const result = scanner.querySelector('[data-scanner-result]');

    let stream = null;
    let scanning = false;
    let inFlight = false;
    // The last code accepted, held only so a badge resting in front of the lens
    // is not read as a fresh scan on every frame.
    let lastPayload = null;
    let lastPayloadAt = 0;

    const setStatus = (message, state = 'idle') => {
        status.classList.remove('is-scanning', 'is-error');
        if (state !== 'idle') status.classList.add(`is-${state}`);
        status.querySelector('span').textContent = message;
    };

    const showResult = (data, state) => {
        result.hidden = false;
        result.classList.remove('is-in', 'is-out', 'is-error');
        result.classList.add(`is-${state}`);

        if (state === 'error') {
            result.querySelector('[data-result-initials]').textContent = '!';
            result.querySelector('[data-result-name]').textContent = 'Not recorded';
            result.querySelector('[data-result-meta]').textContent = data;
            result.querySelector('[data-result-action]').textContent = 'Rejected';
            result.querySelector('[data-result-time]').textContent = '';

            return;
        }

        const employee = data.employee;
        result.querySelector('[data-result-initials]').textContent = employee.initials;
        result.querySelector('[data-result-name]').textContent = employee.name;
        result.querySelector('[data-result-meta]').textContent =
            [employee.employee_number, employee.department].filter(Boolean).join(' · ');
        result.querySelector('[data-result-action]').textContent = data.action === 'check-in'
            ? (data.repeat ? 'Already timed in' : 'Timed in')
            : (data.repeat ? 'Already timed out' : 'Timed out');
        result.querySelector('[data-result-time]').textContent = [
            data.recorded_at,
            data.late_minutes && data.action === 'check-in' ? `${data.late_minutes} min late` : null,
            data.worked_hours ? `${data.worked_hours} worked` : null,
        ].filter(Boolean).join(' · ');
    };

    const submit = async (payload) => {
        inFlight = true;
        setStatus('Reading badge…', 'scanning');

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
            const body = await response.json();

            if (!response.ok) {
                const message = body?.errors
                    ? Object.values(body.errors).flat()[0]
                    : (body?.message ?? 'This scan could not be recorded.');
                showResult(message, 'error');
                setStatus('Ready for the next badge.', 'idle');

                return;
            }

            showResult(body, body.action === 'check-in' ? 'in' : 'out');
            setStatus('Ready for the next badge.', 'idle');

            if (!body.repeat) refreshOwnRecords();
        } catch {
            showResult('The scanner could not reach the server. Check the connection and try again.', 'error');
            setStatus('Ready for the next badge.', 'idle');
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

    const stop = () => {
        scanning = false;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
        scanner.classList.remove('is-live');
        startButton.hidden = false;
        stopButton.hidden = true;
        setStatus('Camera is off.');
    };

    startButton.addEventListener('click', async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            // Browsers withhold the camera API entirely from insecure origins,
            // which is the usual reason it is missing — worth naming, because
            // "no camera" sends people looking at the hardware instead.
            setStatus(
                window.isSecureContext === false
                    ? 'Cameras only work over HTTPS. Open this page as https://…, or as localhost on the machine itself.'
                    : 'This browser cannot open a camera.',
                'error',
            );

            return;
        }

        setStatus('Starting the camera…', 'scanning');

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
            if (['OverconstrainedError', 'NotFoundError'].includes(error?.name)) {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                } catch {
                    setStatus('No camera was found on this device.', 'error');

                    return;
                }
            } else {
                setStatus(cameraFailureMessage(error), 'error');

                return;
            }
        }

        video.srcObject = stream;
        await video.play();
        scanning = true;
        scanner.classList.add('is-live');
        startButton.hidden = true;
        stopButton.hidden = false;
        setStatus('Hold an employee badge in front of the camera.', 'scanning');
        requestAnimationFrame(readFrame);
    });

    stopButton.addEventListener('click', stop);
    // A camera left running behind a closed tab is both a battery drain and a
    // recording light nobody asked for.
    window.addEventListener('pagehide', stop);
};

document.addEventListener('DOMContentLoaded', () => {
    renderProfileCode();
    startScanner();
});
