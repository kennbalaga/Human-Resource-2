<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whether a request is coming from a phone or a tablet.
 *
 * ---- Why this is asked on the server at all ----
 *
 * The app already answers "is this a phone?" in the browser, in app-lock.js,
 * from `(pointer: coarse)` and the screen's shortest edge. That answer is the
 * better one — it describes the actual hardware rather than a string the client
 * chose — but it arrives one full page too late to stop a sign-in. Refusing an
 * HR manager's account on a phone has to happen while the password is being
 * weighed, and at that moment the only thing the server holds is the request.
 *
 * ---- What this can and cannot see ----
 *
 * Client hints are checked first because they are structured: Chromium states
 * `Sec-CH-UA-Mobile: ?1` outright and there is nothing to parse. A `?0` is NOT
 * taken as a denial, though — Chrome sends `?0` on an Android tablet, which is
 * a device this app must still treat as mobile, so a `?0` falls through to the
 * pattern below rather than short-circuiting it.
 *
 * iPadOS is the case this cannot answer. Since iPadOS 13 Safari asks for the
 * desktop site by default and sends a user agent byte-for-byte identical to a
 * Mac's. There is no header that distinguishes them, so an iPad reads as a
 * desktop here and is caught in the browser instead, by mobile-access.js, which
 * can see the pointer type. Neither half is sufficient alone; together they
 * cover every device the hospital actually hands out.
 */
final class MobileDevice
{
    /**
     * Deliberately broad on the mobile side and silent about everything else.
     *
     * `Mobile/` catches iOS WebViews, whose UA carries a build number rather
     * than the word "Safari"; `Tablet` catches Firefox on Android, which says
     * so in as many words instead of dropping "Mobile" the way Chrome does.
     */
    private const MOBILE_PATTERN = '/\b(?:Android|iPhone|iPad|iPod|IEMobile|BlackBerry|BB10|webOS|Kindle|Silk|PlayBook|Tablet)\b|Opera M(?:ini|obi)|Windows Phone|Mobile\//i';

    public static function is(Request $request): bool
    {
        // '?1' is a statement, not a guess, and is the one case worth trusting
        // over the user-agent string it travels beside.
        if (trim((string) $request->headers->get('Sec-CH-UA-Mobile')) === '?1') {
            return true;
        }

        $userAgent = (string) $request->userAgent();

        return $userAgent !== '' && preg_match(self::MOBILE_PATTERN, $userAgent) === 1;
    }
}
