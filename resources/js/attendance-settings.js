document.addEventListener('DOMContentLoaded', () => {
    const settingsPanel = document.querySelector('[data-attendance-settings-sync]');

    if (!settingsPanel) {
        return;
    }

    const attendanceStateUrl = settingsPanel.dataset.attendanceStateUrl;
    const initialAttendanceState = settingsPanel.dataset.attendanceCaptureState;
    const manualModeExpiry = Number(settingsPanel.dataset.manualModeExpiresAt);
    let checkingAttendanceState = false;
    let expiryTimer;

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
            // Keep the settings form usable during a temporary network failure.
        } finally {
            checkingAttendanceState = false;
        }
    };

    const syncExpiredMode = () => {
        if (!Number.isFinite(manualModeExpiry) || manualModeExpiry <= 0) {
            return;
        }

        const millisecondsUntilExpiry = (manualModeExpiry * 1000) - Date.now();

        if (millisecondsUntilExpiry <= 0) {
            window.markIntentionalNavigation();
            window.location.reload();

            return;
        }

        window.clearTimeout(expiryTimer);
        expiryTimer = window.setTimeout(
            syncExpiredMode,
            Math.min(millisecondsUntilExpiry + 250, 60_000),
        );
    };

    syncExpiredMode();
    window.setInterval(checkAttendanceState, 5000);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            syncExpiredMode();
            checkAttendanceState();
        }
    });

    window.addEventListener('focus', () => {
        syncExpiredMode();
        checkAttendanceState();
    });
});
