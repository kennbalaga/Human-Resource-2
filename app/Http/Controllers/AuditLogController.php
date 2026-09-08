<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SpreadsheetExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAuditAccess($request);
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return view('audit-logs.index', [
            'logs' => $this->query($filters)->latest('created_at')->paginate(25)->withQueryString(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAuditAccess($request);
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $logs = $this->query($filters)->latest('created_at')->cursor();

        return response()->streamDownload(function () use ($logs): void {
            $output = fopen('php://output', 'w');
            SpreadsheetExport::writeCsvRow($output, ['Timestamp', 'User', 'Action', 'Method', 'Path', 'Subject Type', 'Subject ID', 'IP Address', 'HTTP Status']);
            foreach ($logs as $log) {
                SpreadsheetExport::writeCsvRow($output, [$log->created_at->toIso8601String(), $log->user?->name, $log->action, $log->method, $log->path, $log->subject_type, $log->subject_id, $log->ip_address, $log->response_status]);
            }
            fclose($output);
        }, 'audit-logs-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters): Builder
    {
        return AuditLog::query()->with('user')
            ->when($filters['user_id'] ?? null, fn (Builder $query, $id) => $query->where('user_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $query, $action) => $query->where('action', 'like', '%'.$action.'%'))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date));
    }

    private function authorizeAuditAccess(Request $request): void
    {
        abort_unless($request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty(), 403);
    }
}
