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

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->record($request, $response);
        }

        return $response;
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
