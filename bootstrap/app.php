<?php

use App\Http\Middleware\AuditDownload;
use App\Http\Middleware\AuditWriteRequests;
use App\Http\Middleware\ConfirmPasswordForDownload;
use App\Http\Middleware\EnforceProductionSecurity;
use App\Http\Middleware\EnforceReadOnlyRole;
use App\Http\Middleware\EnsureAccountIsStillOpen;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSingleActiveSession;
use App\Http\Middleware\PreventAuthenticatedPageCaching;
use App\Http\Middleware\PreventRememberedAuthentication;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration as SentryIntegration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Which proxies may speak for the client through X-Forwarded-*.
        //
        // The loopback default is what a local tunnel (ngrok, cloudflared)
        // terminates on, and trusting it keeps the original HTTPS scheme in
        // generated asset and form URLs. A real deployment sits behind a load
        // balancer at some other address, so this is read from the environment
        // rather than pinned: too narrow and every generated URL falls back to
        // http, too wide and a client picks its own X-Forwarded-For — which is
        // the value the login rate limiter keys on and the audit log records.
        //
        // TRUSTED_PROXIES accepts a comma-separated list, or the single value
        // "*" for the case where the platform guarantees every request already
        // passed through its edge (Cloud Run, Heroku, App Platform).
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1')),
        ), fn (string $proxy): bool => $proxy !== ''));

        $middleware->trustProxies(at: $trustedProxies === ['*'] ? '*' : $trustedProxies);
        $middleware->prepend(EnforceProductionSecurity::class);
        $middleware->authenticateSessions();
        $middleware->web(append: [
            SecurityHeaders::class,
            PreventAuthenticatedPageCaching::class,
            AuditWriteRequests::class,
            EnsureAccountIsStillOpen::class,
            PreventRememberedAuthentication::class,
            EnsureSingleActiveSession::class,
            EnsureRequiredTwoFactorAuthentication::class,
            EnforceReadOnlyRole::class,
        ]);
        $middleware->alias([
            'download.confirm' => ConfirmPasswordForDownload::class,
            'download.audit' => AuditDownload::class,
        ]);
        $middleware->api(append: [SecurityHeaders::class, AuditWriteRequests::class, EnforceReadOnlyRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (TokenMismatchException $exception, Request $request): void {
            Log::notice('HRMS CSRF validation failure.', [
                'event' => 'csrf.failure',
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'request_id' => $request->headers->get('X-Request-ID'),
            ]);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return $request->is('api/*') ? response()->json(['message' => 'Unauthenticated.'], 401) : null;
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            return $request->is('api/*') ? response()->json(['message' => 'Forbidden.'], 403) : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            return $request->is('api/*') ? response()->json(['message' => 'Resource not found.'], 404) : null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || config('app.debug') || $exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json(['message' => 'An unexpected server error occurred.', 'request_id' => $request->headers->get('X-Request-ID')], 500);
        });

        // No-op until SENTRY_LARAVEL_DSN is set (see .env.example) — the SDK
        // itself checks for a configured DSN before sending anything. Skipped
        // entirely when the optional SDK is not installed in vendor/.
        if (class_exists(SentryIntegration::class)) {
            SentryIntegration::handles($exceptions);
        }
    })->create();
