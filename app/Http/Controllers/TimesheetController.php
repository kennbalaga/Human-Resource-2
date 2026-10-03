<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Requests\Timesheet\TimesheetFilterRequest;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Services\TimesheetService;
use App\Support\SpreadsheetExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimesheetController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(TimesheetFilterRequest $request): View
    {
        $filters = $request->validated();
        $canManage = $this->canManage($request);
        $query = $this->query($request, $filters, $canManage);
        $summaryQuery = clone $query;

        return view('timesheets.index', [
            'timesheets' => $query->latest('period_start')->paginate(15)->withQueryString(),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'submitted' => (clone $summaryQuery)->where('status', 'submitted')->count(),
                'approved' => (clone $summaryQuery)->where('status', 'approved')->count(),
                'minutes' => (int) (clone $summaryQuery)->sum('regular_minutes') + (int) (clone $summaryQuery)->sum('overtime_minutes'),
            ],
            'filters' => $filters,
            'canManage' => $canManage,
            'canManageData' => $canManage && $request->user()->canManageData(),
            'departments' => $this->selectableDepartments($request),
            'employees' => Employee::query()->visibleTo($request->user())->notArchived()->where('employment_status', 'active')->orderBy('last_name')->get(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function submit(Request $request, Timesheet $timesheet, TimesheetService $service): RedirectResponse
    {
        $service->submit($timesheet, $request->user());

        return back()->with('success', 'Timesheet submitted for approval.');
    }

    public function approve(Request $request, Timesheet $timesheet, TimesheetService $service): RedirectResponse
    {
        $this->requireManager($request);
        $this->requireSupervision($request, $timesheet->loadMissing('employee')->employee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $service->review($timesheet, $request->user(), 'approved', $validated['reviewer_notes'] ?? null);

        return back()->with('success', 'Timesheet approved.');
    }

    public function reject(Request $request, Timesheet $timesheet, TimesheetService $service): RedirectResponse
    {
        $this->requireManager($request);
        $this->requireSupervision($request, $timesheet->loadMissing('employee')->employee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $service->review($timesheet, $request->user(), 'rejected', $validated['reviewer_notes']);

        return back()->with('success', 'Timesheet returned to the employee.');
    }

    public function export(TimesheetFilterRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        // lazy() rather than cursor(): it still streams, but in chunks that
        // honour the query's eager loads -- cursor() dropped them and cost two
        // lazy loads per row. The id tie-break keeps chunk boundaries stable.
        $records = $this->query($request, $filters, $this->canManage($request))->latest('period_start')->orderByDesc('id')->lazy(500);

        return response()->streamDownload(function () use ($records): void {
            $output = fopen('php://output', 'w');
            SpreadsheetExport::writeCsvRow($output, ['Period Start', 'Period End', 'Employee ID', 'Employee', 'Department', 'Status', 'Regular Minutes', 'Overtime Minutes', 'Late Minutes', 'Undertime Minutes']);
            foreach ($records as $timesheet) {
                SpreadsheetExport::writeCsvRow($output, [$timesheet->period_start->toDateString(), $timesheet->period_end->toDateString(), $timesheet->employee->employee_number, $timesheet->employee->full_name, $timesheet->employee->department?->name, $timesheet->status, $timesheet->regular_minutes, $timesheet->overtime_minutes, $timesheet->late_minutes, $timesheet->undertime_minutes]);
            }
            fclose($output);
        }, 'timesheets-'.$filters['date_from'].'-to-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, mixed> $filters */
    private function query(Request $request, array $filters, bool $canManage): Builder
    {
        return Timesheet::query()
            ->with(['employee.department', 'entries.attendanceRecord.officeLocation', 'reviewer'])
            ->whereDate('period_end', '>=', $filters['date_from'])
            ->whereDate('period_start', '<=', $filters['date_to'])
            // Applied before the request's own filters: a department_id in the
            // query string narrows a head's view, it never widens it.
            ->when($canManage, fn (Builder $query) => Employee::constrainRelatedQuery($query, $request->user()))
            ->when(! $canManage, fn (Builder $query) => $query->where('employee_id', $request->user()->employee?->id))
            ->when($canManage && ! empty($filters['employee_id']), fn (Builder $query) => $query->where('employee_id', $filters['employee_id']))
            ->when($canManage && ! empty($filters['department_id']), fn (Builder $query) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $filters['department_id'])))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status));
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty();
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canManage($request) && $request->user()->canManageData(), 403);
    }
}
