<?php

use App\Http\Middleware\AuditWriteRequests;
use App\Http\Middleware\EnforceProductionSecurity;
use App\Http\Middleware\EnforceReadOnlyRole;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
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
        // Local tunnels such as ngrok terminate HTTPS before forwarding to the
        // application on 127.0.0.1. Trust that local proxy so Laravel keeps
        // the original HTTPS scheme when generating asset and form URLs.
        $middleware->trustProxies(at: ['127.0.0.1']);
        $middleware->prepend(EnforceProductionSecurity::class);
        $middleware->authenticateSessions();
        $middleware->web(append: [
            SecurityHeaders::class,
            AuditWriteRequests::class,
            PreventRememberedAuthentication::class,
            EnsureRequiredTwoFactorAuthentication::class,
            EnforceReadOnlyRole::class,
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
    })->create();
