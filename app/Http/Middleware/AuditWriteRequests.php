<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditWriteRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // CSP reports are untrusted, high-volume telemetry. They are handled by
        // the dedicated logger/rate limiter and must not fill the business audit
        // table or become an audit-log export surface.
        //
        // Biometric punch batches are excluded for the same reason: a bridge
        // agent polling every 60 seconds would add well over a thousand rows a
        // day, and they would be the least informative rows in the table --
        // every punch already keeps its own immutable record in
        // biometric_punches, which is the better audit trail. The security
        // signal below still fires for rejected batches.
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! in_array($request->route()?->getName(), [
                'api.v1.security.csp-report',
                'api.biometric.punches.store',
            ], true)) {
            $this->record($request, $response);
        }

        $this->recordSecuritySignal($request, $response);

        return $response;
    }

    private function recordSecuritySignal(Request $request, Response $response): void
    {
        if (! in_array($response->getStatusCode(), [401, 403, 419, 429], true)) {
            return;
        }

        Log::notice('HRMS security signal', [
            'event' => match ($response->getStatusCode()) {
                401 => 'authentication.failure',
                403 => 'authorization.failure',
                419 => 'csrf.failure',
                429 => 'rate_limit.triggered',
            },
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'response_status' => $response->getStatusCode(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);
    }

    private function record(Request $request, Response $response): void
    {
        try {
            [$subjectType, $subjectId] = $this->subject($request);
            AuditLog::query()->create([
                'user_id' => $request->user()?->id
                    ?? ($request->hasSession() ? $request->session()->get('login.id') : null),
                'action' => $request->route()?->getName() ?? strtolower($request->method()).':'.$request->path(),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_status' => $response->getStatusCode(),
                'metadata' => [
                    'input_fields' => collect($request->except([
                        'password', 'password_confirmation', 'current_password',
                        'code', 'two_factor_code', 'verification_code', 'recovery_code',
                        'token', '_token',
                    ]))->keys()->values()->all(),
                    'request_id' => $request->headers->get('X-Request-ID'),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Audit log write failed without affecting the request.', ['error' => $exception->getMessage()]);
        }
    }

    /** @return array{class-string<Model>|null, int|null} */
    private function subject(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return [$parameter::class, (int) $parameter->getKey()];
            }
        }

        return [null, null];
    }
}
