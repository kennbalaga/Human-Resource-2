document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('attendanceApp');
    const clockElement = document.getElementById('liveAttendanceClock');

    if (!app) {
        return;
    }

    // The clock lives only on the personal view; the scanner view still needs
    // the capture-mode sync below. It sits outside any live region, so a screen
    // reader is not told the time every second.
    if (clockElement) {
        const clockFormatter = new Intl.DateTimeFormat('en-US', {
            timeZone: app.dataset.officeTimezone || 'Asia/Manila',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });

        const updateClock = () => {
            clockElement.textContent = clockFormatter.format(new Date());
        };

        updateClock();
        window.setInterval(updateClock, 1000);
    }

    const manualModeExpiry = Number(app.dataset.manualModeExpiresAt);
    const attendanceStateUrl = app.dataset.attendanceStateUrl;
    const initialAttendanceState = app.dataset.attendanceCaptureState;
    let checkingAttendanceState = false;

    const checkAttendanceState = async () => {
        if (!attendanceStateUrl || !initialAttendanceState || checkingAttendanceState) {
            return;
        }

        checkingAttendanceState = true;

        try {
            const response = await window.fetch(attendanceStateUrl, {
                headers: {
                    Accept: 'application/json',
                },
                cache: 'no-store',
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            const currentState = await response.json();

            if (currentState.state && currentState.state !== initialAttendanceState) {
                window.markIntentionalNavigation();
                window.location.reload();
            }
        } catch {
            // A temporary network failure should not interrupt attendance actions.
        } finally {
            checkingAttendanceState = false;
        }
    };

    window.setInterval(checkAttendanceState, 5000);

    let expiryTimer;

    const syncAttendanceMode = () => {
        if (!Number.isFinite(manualModeExpiry) || manualModeExpiry <= 0) {
            return;
        }

        const manualModeExpiryMilliseconds = manualModeExpiry * 1000;
        const millisecondsUntilExpiry = manualModeExpiryMilliseconds - Date.now();

        if (millisecondsUntilExpiry <= 0) {
            window.markIntentionalNavigation();
            window.location.reload();

            return;
        }

        window.clearTimeout(expiryTimer);
        expiryTimer = window.setTimeout(
            syncAttendanceMode,
            Math.min(millisecondsUntilExpiry + 250, 60_000),
        );
    };

    syncAttendanceMode();

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            syncAttendanceMode();
            checkAttendanceState();
        }
    });

    window.addEventListener('focus', () => {
        syncAttendanceMode();
        checkAttendanceState();
    });
});
