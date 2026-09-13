<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\PayslipFilterRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TimesheetEntry;
use App\Services\Payroll\PayslipBuilder;
use App\Services\Payroll\PayslipPeriod;
use App\Services\ReferenceDataCache;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Payslips, as far as this system can issue them.
 *
 * A payslip is one employee's semi-monthly pay period, gathered from the days
 * on their approved weekly timesheets. There is no table and no release step:
 * approving a timesheet is what puts its days on a payslip. See PayslipBuilder
 * for what it carries and what it leaves to payroll.
 *
 * Access is deliberately narrower than the timesheets screen. Pay is the most
 * sensitive thing an employee record points at, so only HR sees across the
 * workforce. A department head and a system administrator see their own
 * payslip like anyone else, and nobody else's -- see User::canViewPayslipOf().
 */
class PayslipController extends Controller
{
    private const PER_PAGE = 15;

    public function index(PayslipFilterRequest $request): View
    {
        $filters = $request->validated();
        $user = $request->user();
        $seesEveryone = $user->seesEveryPayslip();

        // Widened to whole periods, so a range starting on the 10th still
        // totals the 1st to the 15th rather than a slice of it.
        $from = PayslipPeriod::forDate($filters['date_from']);
        $to = PayslipPeriod::forDate($filters['date_to']);

        $days = TimesheetEntry::query()
            ->join('timesheets', 'timesheets.id', '=', 'timesheet_entries.timesheet_id')
            ->whereBetween('timesheet_entries.work_date', [$from->range()[0], $to->range()[1]])
            ->when(! $seesEveryone, fn (Builder $query) => $query->where('timesheets.employee_id', $user->employee?->id ?? 0))
            ->when($seesEveryone && ! empty($filters['employee_id']), fn (Builder $query) => $query->where('timesheets.employee_id', $filters['employee_id']))
            ->when($seesEveryone && ! empty($filters['department_id']), fn (Builder $query) => $query->whereIn('timesheets.employee_id', Employee::query()->select('id')->where('department_id', $filters['department_id'])))
            ->toBase()
            ->get([
                'timesheets.employee_id',
                'timesheets.status',
                'timesheet_entries.work_date',
                'timesheet_entries.regular_minutes',
                'timesheet_entries.overtime_minutes',
                'timesheet_entries.late_minutes',
            ]);

        $payslips = $this->summarise($days);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('payslips.index', [
            'payslips' => new LengthAwarePaginator(
                $payslips->forPage($page, self::PER_PAGE)->values(),
                $payslips->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'totals' => [
                'payslips' => $payslips->count(),
                'regular_minutes' => $payslips->sum('regular_minutes'),
                'overtime_minutes' => $payslips->sum('overtime_minutes'),
                'late_minutes' => $payslips->sum('late_minutes'),
            ],
            'filters' => $filters,
            'seesEveryone' => $seesEveryone,
            'departments' => $seesEveryone
                ? app(ReferenceDataCache::class)->departments()->where('is_active', true)->sortBy('name')->values()
                : collect(),
            'employees' => $seesEveryone
                ? Employee::query()->notArchived()->orderBy('last_name')->get(['id', 'employee_number', 'first_name', 'last_name'])
                : collect(),
        ]);
    }

    public function show(Request $request, Employee $employee, string $period, PayslipBuilder $builder): View
    {
        $payslip = $this->payslip($request, $employee, $period, $builder);
        $this->recordAccess($request, $employee, $payslip['period'], 'payslips.view');

        return view('payslips.show', ['payslip' => $payslip]);
    }

    public function download(Request $request, Employee $employee, string $period, PayslipBuilder $builder): Response
    {
        $payslip = $this->payslip($request, $employee, $period, $builder);

        // Before the response is built, for the reason ReportController gives:
        // a failed render is exactly the download worth having on record.
        $this->recordAccess($request, $employee, $payslip['period'], 'payslips.download');

        return Pdf::loadView('payslips.pdf', ['payslip' => $payslip])
            ->setPaper('a4', 'portrait')
            ->download('payslip-'.$payslip['number'].'.pdf');
    }

    /**
     * One row per employee and period, from the day-level rows. Done here
     * rather than in SQL because the half-month bucket would need a different
     * date expression on MySQL and on the SQLite the tests run against.
     *
     * @param  Collection<int, object>  $days
     * @return Collection<int, object>
     */
    private function summarise(Collection $days): Collection
    {
        $grouped = $days->groupBy(fn (object $day) => $day->employee_id.'|'.PayslipPeriod::forDate(substr((string) $day->work_date, 0, 10))->key());
        $employees = Employee::withTrashed()
            ->with('department')
            ->whereKey($days->pluck('employee_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $grouped
            ->map(function (Collection $rows, string $group) use ($employees): ?object {
                $approved = $rows->where('status', 'approved');

                if ($approved->isEmpty()) {
                    return null;
                }

                [$employeeId, $key] = explode('|', $group);

                return (object) [
                    'employee' => $employees->get((int) $employeeId),
                    'period' => PayslipPeriod::fromKey($key),
                    'days' => $approved->map(fn (object $day) => substr((string) $day->work_date, 0, 10))->unique()->count(),
                    'regular_minutes' => (int) $approved->sum('regular_minutes'),
                    'overtime_minutes' => (int) $approved->sum('overtime_minutes'),
                    'late_minutes' => (int) $approved->sum('late_minutes'),
                    'complete' => $approved->count() === $rows->count(),
                ];
            })
            ->filter()
            ->sort(fn (object $a, object $b) => [$b->period->key(), $a->employee?->last_name] <=> [$a->period->key(), $b->employee?->last_name])
            ->values();
    }

    /** @return array<string, mixed> */
    private function payslip(Request $request, Employee $employee, string $period, PayslipBuilder $builder): array
    {
        $this->authorizePayslip($request, $employee);

        $payPeriod = PayslipPeriod::fromKey($period);
        abort_if($payPeriod === null, 404);

        // Not found rather than an empty payslip: until a day in the period is
        // approved there is nothing to issue, for its owner or for HR.
        $payslip = $builder->build($employee, $payPeriod);
        abort_if($payslip === null, 404);

        return $payslip;
    }

    private function authorizePayslip(Request $request, Employee $employee): void
    {
        if ($request->user()->canViewPayslipOf($employee)) {
            return;
        }

        Log::notice('HRMS blocked a request for another employee\'s payslip.', [
            'event' => 'authorization.payslip_block',
            'route_name' => $request->route()?->getName(),
            'path' => $request->path(),
            'user_id' => $request->user()->id,
            'target_employee_id' => $employee->id,
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);

        abort(403, 'Payslips are visible only to the employee they belong to and to HR.');
    }

    /**
     * Pay records are read with GETs, which AuditWriteRequests ignores, so the
     * access is written here. An employee opening their own payslip is not
     * worth a row; HR opening someone else's, and any PDF leaving the system,
     * are what the RA 10173 questions after an incident will ask about.
     */
    private function recordAccess(Request $request, Employee $employee, PayslipPeriod $period, string $action): void
    {
        $user = $request->user();

        if ($action === 'payslips.view' && $employee->id === $user->employee?->id) {
            return;
        }

        try {
            AuditLog::query()->create([
                'user_id' => $user->id,
                'action' => $action,
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'subject_type' => Employee::class,
                'subject_id' => $employee->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_status' => 200,
                'metadata' => [
                    'payslip_number' => $period->number($employee),
                    'period_start' => $period->start->toDateString(),
                    'period_end' => $period->end->toDateString(),
                    'request_id' => $request->headers->get('X-Request-ID'),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Payslip access audit write failed without affecting the response.', [
                'error' => $exception->getMessage(),
                'action' => $action,
                'employee_id' => $employee->id,
            ]);
        }
    }
}
