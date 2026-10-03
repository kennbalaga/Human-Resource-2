<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the biometric bridge agent.
 *
 * The bridge is an unattended process on the hospital LAN, not a person: it
 * holds no session and cannot complete two-factor, so it proves itself by
 * signing each request body with a secret it shares with this application.
 *
 * The signature covers the raw body, which is what makes it worth having --
 * it authenticates the punches themselves, not merely the caller, so a
 * truncated or tampered batch fails even from the right IP.
 */
class VerifyBiometricBridgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('attendance.biometric_bridge.secret');

        // An unconfigured secret must refuse everything. Signing with an empty
        // string would otherwise leave the endpoint open to anyone who read
        // the handoff brief and knows how the digest is built.
        if ($secret === '') {
            Log::error('A biometric bridge request arrived while BIOMETRIC_BRIDGE_SECRET was unset.', [
                'event' => 'biometric.bridge.secret_missing',
                'ip_address' => $request->ip(),
            ]);

            return $this->refuse('The biometric bridge is not configured on this server.', 503);
        }

        $provided = (string) $request->headers->get('X-Bridge-Signature', '');
        $device = (string) $request->headers->get('X-Bridge-Device', '');

        if ($provided === '' || $device === '') {
            return $this->refuse('The biometric bridge request is missing its signature headers.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // hash_equals rather than === so a wrong signature costs the same time
        // to reject wherever the first differing byte falls.
        if (! hash_equals($expected, $provided)) {
            Log::warning('A biometric bridge request failed signature verification.', [
                'event' => 'biometric.bridge.signature_mismatch',
                'claimed_device' => mb_substr($device, 0, 120),
                'ip_address' => $request->ip(),
            ]);

            return $this->refuse('The biometric bridge signature did not match.');
        }

        return $next($request);
    }

    private function refuse(string $message, int $status = 401): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
