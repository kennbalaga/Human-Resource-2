/**
 * The shared toast: every "it worked" message in the app, with at most one
 * action — "Undo" — to reverse it. It carries the success flash of a page that
 * just reloaded (drawn by partials/toast) as well as quick actions that saved
 * without reloading.
 *
 * One at a time; a new toast replaces the one showing. It stays at least 4
 * seconds, or 8 when it carries an action, longer for a long message, and the
 * countdown pauses while the pointer or keyboard focus is on it so nobody loses
 * the Undo while reaching for it.
 *
 * Not for errors, warnings, or a success that still asks something of the
 * reader: those stay as banners (see partials/toast).
 */

const PLAIN_MS = 4000;
const ACTION_MS = 8000;
const LONGEST_MS = 10000;

// Enough time to read it: roughly 45ms a character past the first line's
// worth, never less than the floor and never more than ten seconds.
const durationFor = (message, floor) => Math.min(LONGEST_MS, Math.max(floor, 1500 + message.length * 45));

const element = document.querySelector('[data-toast]');
const messageEl = element?.querySelector('[data-toast-message]');
const actionEl = element?.querySelector('[data-toast-action]');
const timerEl = element?.querySelector('[data-toast-timer]');

let timeout = null;
let animation = null;
let endsAt = 0;
let remaining = 0;
let onAction = null;

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
    || document.body.classList.contains('reduce-motion');

const hide = () => {
    clearTimeout(timeout);
    animation?.cancel();
    timeout = null;
    animation = null;
    remaining = 0;
    onAction = null;
    if (element) element.hidden = true;
};

const run = (ms) => {
    clearTimeout(timeout);
    endsAt = Date.now() + ms;
    timeout = setTimeout(hide, ms);
};

/**
 * @param {string} message  What happened, naming the thing: "Jose Reyes taken out of OR 2."
 * @param {{ action?: string, onAction?: () => void }} [options]
 */
export const toast = (message, options = {}) => {
    if (!element) return;

    hide();

    onAction = typeof options.onAction === 'function' ? options.onAction : null;
    actionEl.hidden = !(options.action && onAction);
    actionEl.textContent = options.action ?? '';

    const duration = durationFor(message, actionEl.hidden ? PLAIN_MS : ACTION_MS);

    // The live region has to be showing before its text changes, or a screen
    // reader has nothing to announce.
    messageEl.textContent = '';
    element.hidden = false;
    requestAnimationFrame(() => { messageEl.textContent = message; });
    run(duration);

    if (!reducedMotion()) {
        animation = timerEl.animate(
            [{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }],
            { duration, fill: 'forwards' },
        );
    }
};

if (element) {
    // A success flash the server drew into the page: take it over so it is
    // timed and announced like any other toast.
    const flash = messageEl.textContent.trim();
    if (!element.hidden && flash) {
        toast(flash);
    }

    actionEl.addEventListener('click', () => {
        const callback = onAction;
        hide();
        callback?.();
    });

    const pause = () => {
        if (element.hidden || remaining) return;
        clearTimeout(timeout);
        remaining = Math.max(0, endsAt - Date.now());
        animation?.pause();
    };

    const resume = () => {
        if (element.hidden || !remaining) return;
        if (element.matches(':hover') || element.contains(document.activeElement)) return;
        run(remaining);
        remaining = 0;
        animation?.play();
    };

    element.addEventListener('pointerenter', pause);
    element.addEventListener('focusin', pause);
    element.addEventListener('pointerleave', resume);
    // focusout fires before focus lands on the next element; wait for it.
    element.addEventListener('focusout', () => setTimeout(resume, 0));
}
