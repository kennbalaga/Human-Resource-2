<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes down that a file left the system, for the downloads that do not
 * already say so themselves.
 *
 * AuditWriteRequests skips GET by design, and every download is a GET. Reports
 * (ReportExportAuditor) record richer rows of their own and are not routed
 * through here; this covers the
 * rest -- timesheet, analytics and audit-trail exports, leave attachments and
 * the attendance badge -- so "who took what, when" has an answer for all of
 * them. Refusals are kept too: a 403 on somebody else's fit note is the row a
 * reviewer most wants to find.
 *
 * As with the other auditors, a failed write never costs the user the file.
 */
class AuditDownload
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            [$subjectType, $subjectId] = $this->subject($request);

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => $request->route()?->getName() ?? 'download:'.$request->path(),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_status' => $response->getStatusCode(),
                'metadata' => [
                    // The query string is the description of an extract: the
                    // date range, department and scope that were taken.
                    'filters' => collect($request->query())->except('export_token')
                        ->filter(fn ($value) => $value !== null && $value !== '')
                        ->all(),
                    'request_id' => $request->headers->get('X-Request-ID'),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Download audit write failed without affecting the response.', [
                'error' => $exception->getMessage(),
                'route_name' => $request->route()?->getName(),
            ]);
        }

        return $response;
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
