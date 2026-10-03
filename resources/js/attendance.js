/**
 * The attendance page, kept current without a hand reload.
 *
 * Time is recorded in four places -- this page's own button, the entrance badge
 * scanner, a biometric terminal, and HR closing an open day -- but only the
 * first of those leaves the employee looking at a fresh page. The page already
 * promises "your punches appear here automatically", so it polls: every five
 * seconds it asks the server for a signature of this employee's records and,
 * when that signature moves, re-reads the page and swaps the panels that drew
 * from it.
 *
 * Panels, not the whole document, for three reasons: scroll position survives,
 * a half-typed note survives, and the clock above does not restart. A change of
 * capture mode is the one case that still reloads outright -- that decides
 * whether the page offers a punch control at all, which makes it a different
 * page rather than a fresher one.
 */

const POLL_INTERVAL = 5000;

document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('attendanceApp');

    if (!app) {
        return;
    }

    // The clock re-reads its element on each tick rather than closing over it:
    // it sits inside a panel the refresh below replaces, and a captured node
    // would be left ticking off-screen while the visible one froze. It is
    // outside any live region, so a screen reader is not told the time every
    // second. Absent entirely on the scanner view, which starts no interval.
    if (document.getElementById('liveAttendanceClock')) {
        const clockFormatter = new Intl.DateTimeFormat('en-US', {
            timeZone: app.dataset.officeTimezone || 'Asia/Manila',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true,
        });

        const updateClock = () => {
            const clock = document.getElementById('liveAttendanceClock');

            if (clock) {
                clock.textContent = clockFormatter.format(new Date());
            }
        };

        updateClock();
        window.setInterval(updateClock, 1000);
    }

    const manualModeExpiry = Number(app.dataset.manualModeExpiresAt);
    const attendanceStateUrl = app.dataset.attendanceStateUrl;
    const initialAttendanceState = app.dataset.attendanceCaptureState;
    let attendanceRevision = app.dataset.attendanceRevision;
    let checkingAttendanceState = false;

    const liveRegions = () => app.querySelectorAll('[data-attendance-live]');

    /*
     * Whether somebody is part-way through filling in the manual-attendance
     * reason. Replacing the panel under them would take the field and whatever
     * they had typed with it, so a refresh that lands mid-sentence is held and
     * run when they leave the field instead.
     */
    const isEditing = () => {
        const active = document.activeElement;

        if (!active || !active.matches('input, textarea, select')) {
            return false;
        }

        return [...liveRegions()].some((region) => region.contains(active));
    };

    let refreshPending = false;

    const refreshLiveRegions = async () => {
        const current = liveRegions();

        // The scanner view has none of these, so there is nothing to keep current.
        if (current.length === 0) {
            return;
        }

        if (isEditing()) {
            refreshPending = true;

            return;
        }

        refreshPending = false;

        // The page's own URL, so the window being viewed -- last 7 days or this
        // month -- is the window that comes back.
        const response = await window.fetch(window.location.href, {
            headers: { Accept: 'text/html' },
            cache: 'no-store',
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const fresh = new DOMParser()
            .parseFromString(await response.text(), 'text/html')
            .querySelectorAll('#attendanceApp [data-attendance-live]');

        /*
         * Paired by position, which is why the view renders a fixed number of
         * these in a fixed order. A count that disagrees means what came back
         * is not this page -- an expired session redirected to sign-in, most
         * likely -- and leaving the panels alone is better than blanking them.
         */
        if (fresh.length !== current.length) {
            return;
        }

        current.forEach((region, index) => region.replaceWith(fresh[index]));

        // The swapped-in panels carry a fresh "on the clock for 4h 12m" readout
        // with no ticker behind it yet.
        window.trackElapsed?.();
    };

    const checkAttendanceState = async () => {
        if (!attendanceStateUrl || checkingAttendanceState) {
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

            if (
                initialAttendanceState
                && currentState.state
                && currentState.state !== initialAttendanceState
            ) {
                window.markIntentionalNavigation();
                window.location.reload();

                return;
            }

            // Recorded before the swap is attempted: if it is held back because
            // a field has focus, the held refresh is still the one owed, and a
            // failed fetch is picked up by the next tick anyway.
            if (currentState.revision && currentState.revision !== attendanceRevision) {
                attendanceRevision = currentState.revision;

                await refreshLiveRegions();
            }
        } catch {
            // A temporary network failure should not interrupt attendance actions.
        } finally {
            checkingAttendanceState = false;
        }
    };

    window.setInterval(checkAttendanceState, POLL_INTERVAL);

    // A refresh held while the reason field had focus, run once it is left.
    // focusout lands before the next element takes focus, so the decision waits
    // for the move to settle rather than reading document.body as "not editing".
    app.addEventListener('focusout', () => {
        if (!refreshPending) {
            return;
        }

        window.setTimeout(() => {
            if (refreshPending) {
                refreshLiveRegions();
            }
        }, 0);
    });

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
