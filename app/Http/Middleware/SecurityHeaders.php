<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // camera=(self): the attendance QR scanner reads badges at the entrance,
        // so this origin must be able to ask for the camera at all — with
        // camera=() the browser refuses before the employee is ever prompted,
        // and no camera entry even appears in the site's permissions. Embedded
        // third-party frames stay excluded, as does the microphone, which
        // nothing here has a reason to open.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $this->addContentSecurityPolicy($response);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function addContentSecurityPolicy(Response $response): void
    {
        $mode = (string) config('security.content_security_policy.mode', 'off');

        if (! in_array($mode, ['report-only', 'enforce'], true)) {
            return;
        }

        $directives = config('security.content_security_policy.directives', []);

        if (! is_array($directives) || $directives === []) {
            return;
        }

        $reportUri = trim((string) config('security.content_security_policy.report_uri'));
        if ($reportUri !== '') {
            $directives[] = 'report-uri '.$reportUri;
        }

        $header = $mode === 'enforce'
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        $response->headers->set($header, implode('; ', $directives));
    }
}
