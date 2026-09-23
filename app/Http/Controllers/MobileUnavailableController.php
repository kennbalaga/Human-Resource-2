<?php

namespace App\Http\Controllers;

use App\Services\ActiveDeviceSessionService;
use App\Services\Security\MobileAccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The page a desk-bound account lands on when it reaches the app from a phone.
 *
 * It is deliberately a page and not a flash message on the login form. Somebody
 * standing in a corridor holding a handset that will not let them in needs to
 * read why, and needs to read that it is the account and not the password —
 * otherwise the next thing they do is try the password again, then reset it, then
 * call IT. The login form's one-line error is shown too, for the person who was
 * refused at the door rather than sent here; this is for the person who was
 * already inside.
 *
 * Reachable while signed out on purpose: it is where the refusal lands the
 * browser *after* ending its session.
 */
class MobileUnavailableController extends Controller
{
    public function __construct(
        private readonly MobileAccessPolicy $policy,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        /*
         * Somebody still signed in and still allowed has arrived here by typing
         * the URL or following a stale link. Explaining a restriction that does
         * not apply to them would be a small mystery for no reason, so they are
         * simply returned to work.
         */
        $user = $request->user();

        if ($user !== null && ! $this->policy->denies($request, $user)) {
            return redirect()->route('dashboard');
        }

        return view('mobile.unavailable', [
            'message' => $this->policy->refusalMessage(),
        ]);
    }

    /**
     * End the session and land on the page above.
     *
     * Posted by mobile-access.js, which is the half of the check that can see an
     * iPad for what it is — the user agent cannot, so the server never refuses
     * one and the browser has to say so itself. A POST rather than a link because
     * it signs somebody out, and a GET that ends a session can be triggered by
     * any image tag on any page.
     */
    public function store(Request $request, ActiveDeviceSessionService $activeSession): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            Log::notice('HRMS ended a restricted role\'s session: the browser reported a mobile device.', [
                'event' => 'mobile_access.refused_by_client',
                'user_id' => $user->getKey(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => $request->headers->get('X-Request-ID'),
            ]);

            $activeSession->release($user, $request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('mobile.unavailable');
    }
}
