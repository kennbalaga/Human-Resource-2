/**
 * Touch-device interaction polish.
 *
 * Everything here is gated on a coarse primary pointer and the ≤1100px layout,
 * so the desktop app never binds a single one of these listeners. It changes no
 * behaviour and no flow — the drawer opens and closes exactly where it did, the
 * same links go to the same places. It only makes the existing gestures feel
 * like the phone they are running on.
 */

const isTouchLayout = () =>
    window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(max-width: 1100px)').matches;

/**
 * `100dvh` covers this on anything current. This fallback exists for the
 * Android WebViews and older iOS Safari builds still in service on hospital
 * handsets, where `100vh` is the URL-bar-hidden height and a full-height
 * element always overflows by ~60px.
 */
const trackViewportHeight = () => {
    if (CSS.supports?.('height', '100dvh')) {
        return;
    }

    const apply = () => {
        document.documentElement.style.setProperty('--app-vh', `${window.innerHeight * 0.01}px`);
    };

    apply();
    window.addEventListener('resize', apply, { passive: true });
    window.addEventListener('orientationchange', apply, { passive: true });
};

/**
 * Marks the document when the app is running from the home screen rather than
 * a browser tab, so CSS and other scripts can branch on it. `display-mode`
 * covers Android and desktop; `navigator.standalone` is the iOS-only flag.
 */
const trackDisplayMode = () => {
    const standalone = window.matchMedia('(display-mode: standalone)');

    const apply = () => {
        const installed = standalone.matches || window.navigator.standalone === true;
        document.documentElement.dataset.displayMode = installed ? 'standalone' : 'browser';
    };

    apply();
    standalone.addEventListener?.('change', apply);
};

/**
 * Swipe the navigation drawer.
 *
 * Two gestures, both matching what every Android and iOS app does:
 *   - drag left anywhere on an open drawer to close it,
 *   - drag right from the very left edge of the screen to open it.
 *
 * The direction test is the fiddly part. The drawer is a vertical scroller, so
 * a gesture is only claimed as a drag once the horizontal movement clearly
 * beats the vertical — otherwise scrolling the nav list would drag the drawer
 * sideways with it. Until that threshold the gesture is left entirely alone and
 * the browser scrolls as normal.
 */
const enableDrawerSwipe = () => {
    const sidebar = document.getElementById('appSidebar');
    const body = document.body;

    if (!sidebar) {
        return;
    }

    const EDGE_ZONE = 24;      // px from the left edge that starts an open-swipe
    const CLAIM_THRESHOLD = 12; // px of horizontal travel before we take the gesture
    const COMMIT_FRACTION = 0.4; // how far it must travel to settle open/closed

    let startX = 0;
    let startY = 0;
    let width = 0;
    let tracking = false;
    let claimed = false;
    let openingGesture = false;

    const reset = () => {
        tracking = false;
        claimed = false;
        body.classList.remove('sidebar-dragging');
        sidebar.style.transform = '';
        const overlay = document.querySelector('.sidebar-overlay');

        if (overlay) {
            overlay.style.opacity = '';
        }
    };

    const setOpen = (open) => {
        body.classList.toggle('sidebar-open', open);
        document.querySelector('[data-sidebar-toggle]')?.setAttribute('aria-expanded', String(open));
    };

    document.addEventListener(
        'touchstart',
        (event) => {
            if (!isTouchLayout() || event.touches.length !== 1) {
                return;
            }

            const touch = event.touches[0];
            const isOpen = body.classList.contains('sidebar-open');

            // An open drawer can be dragged from anywhere on it; a closed one
            // only from the screen edge, so the gesture never competes with a
            // horizontally scrolling table in the page.
            if (isOpen) {
                if (!sidebar.contains(event.target)) {
                    return;
                }
            } else if (touch.clientX > EDGE_ZONE) {
                return;
            }

            startX = touch.clientX;
            startY = touch.clientY;
            width = sidebar.offsetWidth;
            tracking = true;
            claimed = false;
            openingGesture = !isOpen;
        },
        { passive: true },
    );

    document.addEventListener(
        'touchmove',
        (event) => {
            if (!tracking || event.touches.length !== 1) {
                return;
            }

            const touch = event.touches[0];
            const deltaX = touch.clientX - startX;
            const deltaY = touch.clientY - startY;

            if (!claimed) {
                if (Math.abs(deltaY) > Math.abs(deltaX)) {
                    // Vertical intent — this is a scroll. Hand it back.
                    tracking = false;

                    return;
                }

                if (Math.abs(deltaX) < CLAIM_THRESHOLD) {
                    return;
                }

                // Wrong direction for the gesture that was started.
                if ((openingGesture && deltaX < 0) || (!openingGesture && deltaX > 0)) {
                    tracking = false;

                    return;
                }

                claimed = true;
                body.classList.add('sidebar-dragging');

                if (openingGesture) {
                    setOpen(true);
                }
            }

            // The gesture is ours now. Without this the page scrolls sideways
            // under the drawer, and in a browser tab iOS reads a left-edge drag
            // as "go back" and navigates away mid-swipe.
            if (event.cancelable) {
                event.preventDefault();
            }

            // Clamp so the drawer cannot be dragged past either resting place.
            const offset = openingGesture
                ? Math.min(0, -width + deltaX)
                : Math.max(-width, deltaX);

            sidebar.style.transform = `translate3d(${offset}px, 0, 0)`;

            const overlay = document.querySelector('.sidebar-overlay');

            if (overlay) {
                overlay.style.opacity = String(1 + offset / width);
            }
        },
        // Not passive: a claimed gesture has to stop the page scrolling under it.
        { passive: false },
    );

    document.addEventListener(
        'touchend',
        (event) => {
            if (!tracking) {
                return;
            }

            if (!claimed) {
                reset();

                return;
            }

            const endX = event.changedTouches[0].clientX;
            const travelled = Math.abs(endX - startX);
            const commit = travelled > width * COMMIT_FRACTION;

            reset();
            setOpen(openingGesture ? commit : !commit);
        },
        { passive: true },
    );

    document.addEventListener('touchcancel', reset, { passive: true });
};

/**
 * A `<select>`, a date field, or a long form on a phone raises a keyboard that
 * covers the bottom 40% of the screen — including, often, the field being
 * typed into. Scrolling the focused element into view after the keyboard has
 * settled is the fix; the delay is what waits for that settling, which fires no
 * event of its own on iOS.
 */
const keepFocusedFieldVisible = () => {
    document.addEventListener('focusin', (event) => {
        const field = event.target;

        if (!field.matches?.('input, select, textarea')) {
            return;
        }

        window.setTimeout(() => {
            if (document.activeElement !== field) {
                return;
            }

            const box = field.getBoundingClientRect();
            const visibleHeight = window.visualViewport?.height ?? window.innerHeight;

            if (box.bottom > visibleHeight - 16 || box.top < 0) {
                field.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }, 320);
    });
};

document.addEventListener('DOMContentLoaded', () => {
    trackDisplayMode();
    trackViewportHeight();

    if (!isTouchLayout()) {
        return;
    }

    enableDrawerSwipe();
    keepFocusedFieldVisible();
});
