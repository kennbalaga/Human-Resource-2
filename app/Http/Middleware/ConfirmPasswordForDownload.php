<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks for the account password again before a file leaves the system.
 *
 * Being signed in proves somebody entered the password at some point today. It
 * does not prove the person at the keyboard now is the one who did: a ward PC
 * left open, or a copied session cookie, reads every screen the owner could.
 * Reading a screen is bounded by what one person can look through; a download
 * is the whole extract, carried off. So downloads ask again, and the answer is
 * trusted for a short window (security.downloads.password_timeout_seconds) so
 * somebody saving six payslips types it once.
 *
 * Normally the asking happens in a modal on the page the link is on
 * (partials.download-confirm), and this middleware never gets to answer: the
 * password is in before the download URL is requested. What it answers is
 * everything else -- a download URL opened directly, a stale bookmark, a
 * browser without the bundle -- with the full page at /confirm-password. That
 * page then returns to where the link was, rather than to the download URL
 * itself, which would leave the user staring at a password form while the file
 * lands behind it (see layouts.app).
 */
class ConfirmPasswordForDownload
{
    public const PENDING_KEY = 'download.pending';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::recentlyConfirmed($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Password confirmation required.'], 423);
        }

        $request->session()->put(self::PENDING_KEY, [
            'url' => $request->fullUrl(),
            'return_to' => $this->returnTo($request),
        ]);

        return redirect()->route('password.confirm');
    }

    public static function recentlyConfirmed(Request $request): bool
    {
        return self::confirmedUntil($request) > Date::now()->unix();
    }

    /**
     * When the current confirmation runs out, as a Unix timestamp -- 0 when
     * there is none. The page reads this so a link clicked inside the window
     * downloads without a modal in the way, and so the modal comes back by
     * itself on a tab left open past the timeout.
     */
    public static function confirmedUntil(Request $request): int
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if ($confirmedAt === 0) {
            return 0;
        }

        return $confirmedAt + (int) config('security.downloads.password_timeout_seconds', 900);
    }

    /**
     * The page the link sat on, taken from the Referer. Only ever a page of this
     * application: anything else would turn the confirmation form into an open
     * redirect.
     */
    private function returnTo(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');

        if (! str_starts_with($referer, $request->getSchemeAndHttpHost().'/')
            || $referer === $request->fullUrl()) {
            return null;
        }

        return $referer;
    }
}
