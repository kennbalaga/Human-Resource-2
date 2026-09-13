<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\TimesheetEntry;
use App\Services\LeaveService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The attendance side of a semi-monthly payslip.
 *
 * Payroll is not this system's to run. What it owns is the basis payroll pays
 * on -- the days scheduled, worked and missed, the hours, the leave and the
 * holidays -- so that is what the payslip carries. The earnings and deductions
 * are laid out so the document reads as a payslip, but every amount is left to
 * payroll and labelled as such rather than estimated here.
 *
 * Only days from approved timesheets are counted. A period whose weeks are not
 * all approved yet still has a payslip, marked partial, with the unapproved
 * days shown apart so they are not mistaken for absences.
 *
 * Expected days follow the rule AbsenceCalculator applies to the attendance
 * report: published roster assignments when there are any, otherwise weekdays
 * since hire less recorded days off and holidays, with approved leave deducted
 * either way.
 */
class PayslipBuilder
{
    /**
     * Shown so the payslip has its familiar shape. Nothing here is computed.
     *
     * @var array<int, string>
     */
    public const EARNINGS = ['Basic Pay', 'Overtime Pay', 'Holiday Pay', 'Night Differential', 'Allowances'];

    /** @var array<int, string> */
    public const DEDUCTIONS = ['SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Late / Undertime'];

    public function __construct(private readonly LeaveService $leaves) {}

    /**
     * Null when no approved day falls in the period: there is no payslip yet.
     *
     * @return array{
     *     employee: Employee,
     *     period: PayslipPeriod,
     *     number: string,
     *     complete: bool,
     *     weeks: array{approved: int, pending: int},
     *     approved_at: ?Carbon,
     *     approved_by: ?string,
     *     days: array{scheduled: int, worked: int, leave: float, pending: int, absent: int},
     *     minutes: array{regular: int, overtime: int, late: int, undertime: int},
     *     leave: array<int, array{type: string, category: string, from: Carbon, to: Carbon, days: float}>,
     *     holidays: Collection<int, Holiday>,
     *     entries: Collection<int, TimesheetEntry>,
     *     earnings: array<int, string>,
     *     deductions: array<int, string>,
     * }|null
     */
    public function build(Employee $employee, PayslipPeriod $period): ?array
    {
        $range = $period->range();

        [$entries, $pendingEntries] = TimesheetEntry::query()
            ->with('timesheet.reviewer')
            ->whereHas('timesheet', fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->whereBetween('work_date', $range)
            ->orderBy('work_date')
            ->get()
            ->partition(fn (TimesheetEntry $entry) => $entry->timesheet->status === 'approved');

        if ($entries->isEmpty()) {
            return null;
        }

        $employee->loadMissing(['department', 'position']);

        $approvedWeeks = $entries->pluck('timesheet')->unique('id');
        $lastApproval = $approvedWeeks->sortByDesc('reviewed_at')->first();

        $worked = $this->distinctDays($entries);
        $pending = $this->distinctDays($pendingEntries);
        $holidays = Holiday::query()->whereBetween('date', $range)->orderBy('date')->get();
        $leave = $this->leaveInPeriod($employee, $period);
        $leaveDays = (float) collect($leave)->sum('days');

        // Never fewer than the days actually worked. Without a roster the
        // expectation is weekdays, so a weekend shift -- or a hire that starts
        // on a Saturday -- would otherwise read as working more days than were
        // scheduled, and an approved day of work was plainly a working day.
        $scheduled = max($this->expectedDays($employee, $period, $holidays), $worked + $pending);

        return [
            'employee' => $employee,
            'period' => $period,
            'number' => $period->number($employee),
            'complete' => $pendingEntries->isEmpty(),
            'weeks' => [
                'approved' => $approvedWeeks->count(),
                'pending' => $pendingEntries->pluck('timesheet')->unique('id')->count(),
            ],
            'approved_at' => $lastApproval?->reviewed_at,
            'approved_by' => $lastApproval?->reviewer?->name,
            'days' => [
                'scheduled' => $scheduled,
                'worked' => $worked,
                'leave' => $leaveDays,
                'pending' => $pending,
                'absent' => max(0, $scheduled - (int) round($leaveDays) - $worked - $pending),
            ],
            'minutes' => [
                'regular' => (int) $entries->sum('regular_minutes'),
                'overtime' => (int) $entries->sum('overtime_minutes'),
                'late' => (int) $entries->sum('late_minutes'),
                'undertime' => (int) $entries->sum('undertime_minutes'),
            ],
            'leave' => $leave,
            'holidays' => $holidays,
            'entries' => $entries->values(),
            'earnings' => self::EARNINGS,
            'deductions' => self::DEDUCTIONS,
        ];
    }

    /** @param  Collection<int, TimesheetEntry>  $entries */
    private function distinctDays(Collection $entries): int
    {
        return $entries->map(fn (TimesheetEntry $entry) => $entry->work_date->toDateString())->unique()->count();
    }

    /** @param  Collection<int, Holiday>  $holidays */
    private function expectedDays(Employee $employee, PayslipPeriod $period, Collection $holidays): int
    {
        $range = $period->range();

        // A published roster already decides who works a holiday -- a hospital
        // staffs its wards on Christmas -- so holidays only thin the fallback.
        $rostered = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', $range)
            ->distinct()
            ->count('work_date');

        if ($rostered > 0) {
            return $rostered;
        }

        $start = $employee->hire_date && $employee->hire_date->greaterThan($period->start)
            ? $employee->hire_date->copy()->startOfDay()
            : $period->start;

        if ($start->greaterThan($period->end)) {
            return 0;
        }

        $off = ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', $range)
            ->pluck('work_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->merge($holidays->map(fn (Holiday $holiday) => $holiday->date->toDateString()))
            ->unique()
            ->flip();

        return collect(CarbonPeriod::create($start, $period->end))
            ->map(fn ($date) => Carbon::instance($date))
            ->reject(fn (Carbon $date) => $date->isWeekend() || $off->has($date->toDateString()))
            ->count();
    }

    /**
     * Approved leave clamped to the period, so a fortnight's leave that starts
     * on the 12th shows only the days that fall on this payslip.
     *
     * @return array<int, array{type: string, category: string, from: Carbon, to: Carbon, days: float}>
     */
    private function leaveInPeriod(Employee $employee, PayslipPeriod $period): array
    {
        [$from, $until] = $period->range();

        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $until)
            ->where('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get()
            ->map(function (LeaveRequest $request) use ($period): array {
                $start = $request->start_date->greaterThan($period->start) ? $request->start_date->copy() : $period->start->copy();
                $end = $request->end_date->lessThan($period->end) ? $request->end_date->copy() : $period->end->copy();

                return [
                    'type' => $request->leaveType?->name ?? 'Leave',
                    'category' => $request->leaveType?->isStatutory() ? 'Statutory' : 'Company allowance',
                    'from' => $start,
                    'to' => $end,
                    'days' => $request->leaveType ? $this->leaves->countDays($start, $end, $request->leaveType) : 0.0,
                ];
            })
            ->all();
    }
}
