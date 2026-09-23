/**
 * "Is this a phone or a tablet?", answered once for everything that asks.
 *
 * Three features now need this and they must not disagree. The app lock uses it
 * to decide whether to offer a PIN at all; the trusted-device sync uses it to
 * decide whether there is a PIN worth trading for the sign-in code; and the
 * role restriction uses it to catch the one device the server cannot see. If
 * any two of them drew the line differently, a device would end up with a lock
 * it could not trade, or a trade with no lock behind it.
 */

/**
 * A decision about the device, not the window. A desktop browser dragged narrow
 * is still a desktop and must not be offered any of this; a touchscreen laptop
 * reports coarse touch points but its *primary* pointer is a mouse, so
 * `(pointer: coarse)` excludes it correctly.
 *
 * The screen check uses the smaller of the two screen dimensions so the answer
 * does not change when the phone is rotated. 1100 CSS pixels is above every
 * phone and tablet in portrait and below a laptop panel, including the
 * convertibles that report coarse pointers in tablet mode.
 */
export const isMobileDevice = () => {
    if (!window.matchMedia('(pointer: coarse)').matches) {
        return false;
    }

    const shortestEdge = Math.min(window.screen?.width ?? 0, window.screen?.height ?? 0);

    return shortestEdge > 0 && shortestEdge <= 1100;
};
