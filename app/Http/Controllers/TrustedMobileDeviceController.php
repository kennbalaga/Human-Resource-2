<?php

namespace App\Http\Controllers;

use App\Services\Security\MobileAccessPolicy;
use App\Services\Security\TrustedMobileDeviceService;
use App\Support\MobileDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Arms and disarms the phone whose app lock stands in for the authenticator code.
 *
 * ---- What this endpoint is not ----
 *
 * It is not a home for the app lock. Nothing it accepts describes the PIN, its
 * digest, its salt, the WebAuthn credential id or whether a fingerprint was
 * enrolled — the request body is empty, and the only thing that comes back is an
 * opaque token. The app lock stays exactly as device-local as it was; what is
 * recorded here is one bit of derived fact, "a lock exists on this handset", plus
 * the material needed to prove later that it is the same handset.
 *
 * ---- Why no password re-entry ----
 *
 * The caller is already inside a session that got past the password and, if the
 * account had two-factor enrolled, past a code as well. Asking again would be
 * asking the same question a third time. What the token buys is the *next*
 * sign-in on this handset, and that sign-in is still behind the password.
 */
class TrustedMobileDeviceController extends Controller
{
    public function __construct(
        private readonly TrustedMobileDeviceService $trustedDevices,
        private readonly MobileAccessPolicy $policy,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
         * Belt beside the middleware's braces. A restricted role cannot reach a
         * phone at all, so it can have no app lock to trade — and a request from
         * one here would mean either the middleware was bypassed or the role
         * changed mid-session. Neither should mint a token.
         */
        if ($this->policy->restricts($user)) {
            return response()->json([
                'message' => $this->policy->refusalMessage(),
            ], 403);
        }

        // A desktop has no app lock: app-lock.js refuses to offer one there, so a
        // desktop asking to be trusted is asking on the strength of nothing.
        if (! MobileDevice::is($request)) {
            return response()->json([
                'message' => 'Only a phone or tablet with an app lock can be trusted for sign-in.',
            ], 422);
        }

        return response()->json([
            'token' => $this->trustedDevices->arm($user, $request),
        ])->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->trustedDevices->revoke($request->user(), $request);

        return response()->json(['trusted' => false]);
    }
}
