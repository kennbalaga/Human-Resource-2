<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CspReportController extends Controller
{
    private const MAX_REPORT_BYTES = 16_384;

    /** @var array<int, string> */
    private const ALLOWED_FIELDS = [
        'document-uri',
        'referrer',
        'violated-directive',
        'effective-directive',
        'original-policy',
        'disposition',
        'blocked-uri',
        'status-code',
        'source-file',
        'line-number',
        'column-number',
    ];

    public function __invoke(Request $request): Response
    {
        if (strlen($request->getContent()) > self::MAX_REPORT_BYTES) {
            abort(413);
        }

        $payload = json_decode($request->getContent(), true);
        $report = is_array($payload) ? ($payload['csp-report'] ?? $payload) : [];
        $safe = [];

        if (is_array($report)) {
            foreach (self::ALLOWED_FIELDS as $field) {
                if (array_key_exists($field, $report) && (is_scalar($report[$field]) || $report[$field] === null)) {
                    $safe[$field] = is_string($report[$field])
                        ? mb_substr($report[$field], 0, 2_048)
                        : $report[$field];
                }
            }
        }

        Log::warning('A browser reported a Content Security Policy violation.', [
            'report' => $safe,
            'ip_address' => $request->ip(),
        ]);

        return response()->noContent();
    }
}
