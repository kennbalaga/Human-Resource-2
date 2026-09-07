<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Without this, a browser's back/forward cache is free to resurrect an
 * authenticated page verbatim after logout — the schedule, roster, or
 * employee data last rendered stays on screen (with its JS timers frozen
 * mid-state) even though the session behind it is already gone. Marking the
 * response no-store is what tells the browser that page can never be
 * replayed, so back/forward always re-hits the server and lands on login
 * once the session is invalid.
 */
class PreventAuthenticatedPageCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A handful of endpoints (keepAlive, the attendance state poll) already
        // set their own no-store Cache-Control — leave those exact values alone
        // rather than overwriting them with a differently-worded equivalent.
        $alreadyNoStore = str_contains((string) $response->headers->get('Cache-Control'), 'no-store');

        if ($request->user() !== null && ! $alreadyNoStore) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
