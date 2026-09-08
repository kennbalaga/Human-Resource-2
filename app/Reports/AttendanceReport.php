<?php

namespace App\Reports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Reports\AbsenceCalculator;
use Illuminate\Database\Eloquent\Builder;

class AttendanceReport extends Report
{
    public function __construct(private readonly AbsenceCalculator $absences) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance';
    }

    public function description(): string
    {
        return 'Work hours, late arrivals, undertime and overtime, one row per recorded day.';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function filters(): array
    {
        return ['department_id', 'employee_id', 'status', 'approval_status', 'capture_method'];
    }

    /**
     * Attendance keeps its own table rather than the shared one: its rows carry
     * an avatar, paired source-and-device cells, status badges and the approval
     * controls, and this is the only screen in the application from which an
     * attendance record can be approved at all.
     */
    public function view(): string
    {
        return 'attendance.reports.index';
    }

    public function columns(): array
    {
        $zone = fn (AttendanceRecord $record) => $record->officeLocation?->timezone ?? 'Asia/Manila';

        return [
            ReportColumn::make('Date', fn (AttendanceRecord $r) => $r->attendance_date->toDateString()),
            ReportColumn::make('Employee ID', fn (AttendanceRecord $r) => $r->employee->employee_number),
            ReportColumn::make('Employee', fn (AttendanceRecord $r) => $r->employee->full_name),
            ReportColumn::make('Department', fn (AttendanceRecord $r) => $r->employee->department?->name),
            ReportColumn::make('Check In', fn (AttendanceRecord $r) => $r->check_in_at?->timezone($zone($r))->format('Y-m-d H:i:s')),
            ReportColumn::make('Check Out', fn (AttendanceRecord $r) => $r->check_out_at?->timezone($zone($r))->format('Y-m-d H:i:s')),
            ReportColumn::make('Check In Source', fn (AttendanceRecord $r) => $r->check_in_method)->wide(),
            ReportColumn::make('Check In Device', fn (AttendanceRecord $r) => $r->checkInBiometricDevice?->name)->wide(),
            ReportColumn::make('Check Out Source', fn (AttendanceRecord $r) => $r->check_out_method)->wide(),
            ReportColumn::make('Check Out Device', fn (AttendanceRecord $r) => $r->checkOutBiometricDevice?->name)->wide(),
            ReportColumn::make('Status', fn (AttendanceRecord $r) => $r->status),
            // On screen since approvals moved onto this table, and in neither
            // export until the columns were declared in a single place.
            ReportColumn::make('Approval', fn (AttendanceRecord $r) => $r->approval_status),
            ReportColumn::make('Late Minutes', fn (AttendanceRecord $r) => $r->late_minutes)->numeric(),
            ReportColumn::make('Worked Minutes', fn (AttendanceRecord $r) => $r->worked_minutes)->numeric(),
            ReportColumn::make('Undertime Minutes', fn (AttendanceRecord $r) => $r->undertime_minutes)->numeric(),
            ReportColumn::make('Overtime Minutes', fn (AttendanceRecord $r) => $r->overtime_minutes)->numeric(),
        ];
    }

    public function query(array $filters): Builder
    {
        return AttendanceRecord::query()
            // `employee.user` and `employee.position` were eager-loaded here for
            // years and read by nothing: two joins per page of a report that
            // shows neither a login nor a job title.
            ->with(['employee.department', 'officeLocation', 'checkInBiometricDevice', 'checkOutBiometricDevice'])
            // Not whereDate(): wrapping the column in DATE() hides it from the
            // (attendance_date, status) index this table carries for exactly
            // this query. See Report::rangeStart() for why the bounds are
            // widened to whole days rather than passed as bare date strings.
            ->whereBetween('attendance_date', [$this->rangeStart($filters), $this->rangeEnd($filters)])
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query
                ->where('employee_id', $employeeId))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query
                ->where('status', $status))
            ->when($filters['approval_status'] ?? null, fn (Builder $query, $status) => $query
                ->where('approval_status', $status))
            ->when($filters['capture_method'] ?? null, function (Builder $query, string $method): void {
                if ($method === 'mixed') {
                    $query->whereNotNull('check_out_method')->whereColumn('check_in_method', '!=', 'check_out_method');

                    return;
                }

                $query->where(function (Builder $sourceQuery) use ($method): void {
                    $sourceQuery->where('check_in_method', $method)->orWhere('check_out_method', $method);
                });
            })
            ->latest('attendance_date')
            ->latest('check_in_at');
    }

    public function applyScope(Builder $query, ?User $user): Builder
    {
        return Employee::constrainRelatedQuery($query, $user);
    }

    public function summary(array $filters, ?User $user): array
    {
        // One grouped pass in place of the six cloned counts this replaced --
        // records, present, late, worked, overtime and pending each paid for
        // their own full scan of the same range.
        $totals = $this->scopedQuery($filters, $user)
            ->reorder()
            ->toBase()
            ->selectRaw('count(*) as records')
            ->selectRaw("sum(case when status = 'late' then 1 else 0 end) as late")
            ->selectRaw('sum(worked_minutes) as worked_minutes')
            ->selectRaw('sum(overtime_minutes) as overtime_minutes')
            ->selectRaw("sum(case when approval_status = 'pending' then 1 else 0 end) as pending")
            ->first();

        return [
            // A late arrival is somebody who came to work. Counting only
            // status = 'present' here meant the tile headed "Present" actually
            // read "on time", and showed zero on a ward where everyone had
            // clocked in behind the grace period.
            self::tile('Present', number_format((int) ($totals->records ?? 0)), 'check-circle', 'green'),
            self::tile('Late', number_format((int) ($totals->late ?? 0)), 'clock', 'amber'),
            self::tile('Absent', number_format($this->absences->forPeriod($filters, $user)), 'users', 'red'),
            self::tile('Total work', self::hours((int) ($totals->worked_minutes ?? 0)), 'clock', 'violet'),
            self::tile('Overtime', self::hours((int) ($totals->overtime_minutes ?? 0)), 'arrow-up', 'green'),
            self::tile('Pending approval', number_format((int) ($totals->pending ?? 0)), 'check-circle', 'amber'),
        ];
    }

    /**
     * @return array{label: string, value: string, icon: string, tone: string}
     */
    public static function tile(string $label, string $value, string $icon, string $tone): array
    {
        return ['label' => $label, 'value' => $value, 'icon' => $icon, 'tone' => $tone];
    }

    public static function hours(int $minutes): string
    {
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
