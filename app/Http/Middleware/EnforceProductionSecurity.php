<?php

namespace App\Http\Middleware;

use App\Services\Security\ProductionSecurityChecker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnforceProductionSecurity
{
    public function __construct(private readonly ProductionSecurityChecker $checker) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction() || ! config('security.production.enforce')) {
            return $next($request);
        }

        $failures = $this->checker->criticalFailures();

        if ($failures === []) {
            return $next($request);
        }

        Log::critical('The production security guard blocked a request because required controls are missing.', [
            'failed_checks' => array_column($failures, 'name'),
        ]);

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Service temporarily unavailable.'], 503);
        }

        return response('Service temporarily unavailable.', 503)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
