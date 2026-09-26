/**
 * Closing a floating menu the way people expect one to close.
 *
 * The menus themselves are plain <details>, so they open, close and take the
 * keyboard without any of this — which is why it is written as an enhancement
 * rather than as the mechanism. What <details> does not do is shut when you
 * tap somewhere else, and a menu floating over the page is exactly the kind
 * that should: on a phone it covers the list it was opened from, and leaving
 * it there means the next tap goes to the menu instead of the card underneath.
 *
 * Marked with data-fab-menu rather than by class, so a second floating menu
 * elsewhere gets the same behaviour by saying so.
 */

const SELECTOR = 'details[data-fab-menu]';

const closeAll = (except = null) => {
    document.querySelectorAll(`${SELECTOR}[open]`).forEach((menu) => {
        if (menu !== except) {
            menu.open = false;
        }
    });
};

document.addEventListener('click', (event) => {
    const inside = event.target.closest?.(SELECTOR) ?? null;

    // A tap inside one open menu still closes any other, so two can never be
    // open over each other.
    closeAll(inside);
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    const open = document.querySelector(`${SELECTOR}[open]`);

    if (open) {
        open.open = false;
        // Back to the control that opened it, or the focus ring is left
        // somewhere the reader cannot see.
        open.querySelector('summary')?.focus();
    }
});
