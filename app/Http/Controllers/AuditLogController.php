<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditActivity;
use App\Support\SpreadsheetExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAuditAccess($request);
        $filters = $this->filters($request);

        return view('audit-logs.index', [
            'logs' => $this->query($filters)->with(['user.roles'])->latest('created_at')->paginate(25)->withQueryString(),
            'summary' => $this->summary($filters),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'activeFilters' => $this->describeActiveFilters($filters),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAuditAccess($request);

        // "all" is a deliberate, separate choice in the export menu rather than
        // the default: an administrator who has narrowed the page to one nurse's
        // week should get that week, not 3,000 unrelated rows they then have to
        // re-filter in a spreadsheet — and the wider extract is itself a
        // disclosure worth making someone ask for.
        $filters = $request->query('scope') === 'all' ? [] : $this->filters($request);
        $logs = $this->query($filters)->with('user')->latest('created_at')->cursor();

        $response = response()->streamDownload(function () use ($logs): void {
            $output = fopen('php://output', 'w');
            SpreadsheetExport::writeCsvRow($output, [
                'Timestamp', 'User', 'Action', 'Activity Type', 'Module', 'Method', 'Path',
                'Subject Type', 'Subject ID', 'IP Address', 'HTTP Status', 'Outcome',
            ]);
            foreach ($logs as $log) {
                SpreadsheetExport::writeCsvRow($output, [
                    $log->created_at->toIso8601String(),
                    $log->user?->name ?? 'Unauthenticated',
                    $log->action,
                    AuditActivity::activity($log)['label'],
                    AuditActivity::module($log)['label'],
                    $log->method,
                    $log->path,
                    $log->subject_type ? class_basename($log->subject_type) : null,
                    $log->subject_id,
                    $log->ip_address,
                    $log->response_status,
                    AuditActivity::status($log->response_status)['label'],
                ]);
            }
            fclose($output);
        }, 'audit-logs-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);

        // A streamed download never navigates, so the browser fires no load or
        // error event the page can hear and the button would sit on "Preparing…"
        // forever. The page sends a token it invented and watches for it to come
        // back as a cookie, which only happens once these headers are flushed —
        // that is the one signal available that the file is genuinely on its way.
        if ($token = $request->query('export_token')) {
            $response->headers->setCookie(
                Cookie::make('audit_export_token', (string) $token, 1, '/', null, $request->secure(), false)
            );
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'module' => ['nullable', 'string', 'in:'.implode(',', [...array_keys(AuditActivity::MODULES), 'other'])],
            'activity' => ['nullable', 'string', 'in:'.implode(',', array_keys(AuditActivity::ACTIVITIES))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(AuditActivity::STATUS_GROUPS))],
        ]);
    }

    /**
     * The four counters above the table, measured over the filtered set rather
     * than the visible page — "6 refused actions" is only meaningful if it
     * counts the whole search, not whichever 25 rows happen to be on screen.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $totals = $this->query($filters)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when response_status >= 400 then 1 else 0 end) as attention')
            ->selectRaw("sum(case when method in ('PUT', 'PATCH', 'DELETE') then 1 else 0 end) as changes")
            ->selectRaw('count(distinct user_id) as actors')
            ->first();

        return [
            'total' => (int) ($totals->total ?? 0),
            'attention' => (int) ($totals->attention ?? 0),
            'changes' => (int) ($totals->changes ?? 0),
            'actors' => (int) ($totals->actors ?? 0),
        ];
    }

    /**
     * Active filters as removable chips: each one carries the query string that
     * would remain if that single filter were dropped, so a reviewer can peel
     * back one condition at a time instead of clearing everything and starting
     * the search again.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{label: string, value: string, query: array<string, mixed>}>
     */
    private function describeActiveFilters(array $filters): array
    {
        $applied = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $labels = [
            'user_id' => 'User',
            'action' => 'Action contains',
            'date_from' => 'From',
            'date_to' => 'To',
            'module' => 'Module',
            'activity' => 'Activity',
            'status' => 'Outcome',
        ];

        return collect($applied)->map(fn ($value, $key) => [
            'label' => $labels[$key] ?? $key,
            'value' => match ($key) {
                'user_id' => User::query()->find($value)?->name ?? 'Unknown user',
                'module' => AuditActivity::MODULES[$value]['label'] ?? 'Other',
                'activity' => AuditActivity::ACTIVITIES[$value] ?? $value,
                'status' => AuditActivity::STATUS_GROUPS[$value] ?? $value,
                default => (string) $value,
            },
            'query' => array_diff_key($applied, [$key => null]),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['user_id'] ?? null, fn (Builder $query, $id) => $query->where('user_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $query, $action) => $query->where('action', 'like', '%'.$action.'%'))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['module'] ?? null, fn (Builder $query, $module) => AuditActivity::scopeModule($query, $module))
            ->when($filters['activity'] ?? null, fn (Builder $query, $activity) => AuditActivity::scopeActivity($query, $activity))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => AuditActivity::scopeStatus($query, $status));
    }

    private function authorizeAuditAccess(Request $request): void
    {
        abort_unless($request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty(), 403);
    }
}
