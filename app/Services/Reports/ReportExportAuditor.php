<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Reports\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records that workforce data left the system as a file.
 *
 * `AuditWriteRequests` deliberately ignores GET, which is right for a middleware
 * whose job is to catch changes -- but every report export is a GET, so the one
 * action in this module that copies personal data out of it was the one action
 * nothing wrote down. Somebody could pull three months of the whole hospital's
 * movements and the audit trail would show them having read a page.
 *
 * That matters here beyond tidiness. docs/DATA_PRIVACY.md scopes this system
 * under RA 10173, where the questions asked after an incident are which records
 * left, when, and at whose request. The row count and the filters are recorded
 * for that reason: knowing an export happened is not the same as knowing what
 * was in it.
 *
 * A failure to audit must never cost the user their download, so this follows
 * the middleware in swallowing its own errors to a log line.
 */
class ReportExportAuditor
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function record(Request $request, Report $report, array $filters, string $format, int $rows): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'reports.export',
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_status' => 200,
                'metadata' => [
                    'report' => $report->key(),
                    'format' => $format,
                    // The filters are the description of the extract. Without
                    // them the entry says a report was taken but not of whom.
                    'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
                    'row_count' => $rows,
                    'request_id' => $request->headers->get('X-Request-ID'),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Report export audit write failed without affecting the download.', [
                'error' => $exception->getMessage(),
                'report' => $report->key(),
                'format' => $format,
            ]);
        }
    }
}
