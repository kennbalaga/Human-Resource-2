/**
 * Keeps the screen awake while the attendance badge is on it.
 *
 * A phone dims and then sleeps after fifteen or thirty seconds. That is exactly
 * the length of a queue at the entrance, so without this the badge is dark by
 * the time the officer's camera reaches it and the employee is re-unlocking a
 * phone one-handed with people behind them.
 *
 * The Screen Wake Lock API cannot raise brightness — nothing on the web can —
 * so this only stops the screen going out. The line in the page that says so is
 * hidden until the lock is actually held: a browser that refuses (Safari before
 * 16.4, a battery-saver mode, an insecure context) must not leave the page
 * claiming something that did not happen.
 */

const AWAKE_NOTE = '[data-badge-awake]';

let lock = null;

const note = () => document.querySelector(AWAKE_NOTE);

const showNote = (held) => {
    const element = note();

    if (element) {
        element.hidden = !held;
    }
};

const acquire = async () => {
    if (!('wakeLock' in navigator)) {
        return;
    }

    try {
        lock = await navigator.wakeLock.request('screen');
        showNote(true);

        // The browser drops the lock whenever the page is hidden — switching
        // apps, answering a call — and does not restore it on the way back.
        lock.addEventListener('release', () => {
            lock = null;
            showNote(false);
        });
    } catch (error) {
        // Refused: battery saver, an insecure context, or a browser without it.
        // The badge still works; it just goes dark on its own schedule.
        showNote(false);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (!note()) {
        return;
    }

    acquire();

    // Coming back to the tab is the one moment worth re-asking: the lock was
    // released on the way out, and the person is almost certainly back because
    // they are at the door now.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && lock === null) {
            acquire();
        }
    });
});
