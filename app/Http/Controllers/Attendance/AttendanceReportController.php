<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceReportRequest;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    private const EXPORT_COLUMNS = [
        'Date', 'Employee ID', 'Employee', 'Department', 'Check In', 'Check Out',
        'Check In Source', 'Check In Device', 'Check Out Source', 'Check Out Device',
        'Status', 'Late Minutes', 'Worked Minutes', 'Undertime Minutes', 'Overtime Minutes',
    ];

    public function index(AttendanceReportRequest $request): View
    {
        $filters = $request->validated();
        $query = $this->reportQuery($filters);

        $summary = [
            'records' => (clone $query)->count(),
            'present' => (clone $query)->where('status', 'present')->count(),
            'late' => (clone $query)->where('status', 'late')->count(),
            'worked_minutes' => (int) (clone $query)->sum('worked_minutes'),
            'overtime_minutes' => (int) (clone $query)->sum('overtime_minutes'),
            'absent' => null,
            'pending_approval' => (clone $query)->where('approval_status', 'pending')->count(),
        ];

        if ($filters['date_from'] === $filters['date_to']) {
            $employeesQuery = Employee::query()->where('employment_status', 'active');
            if (! empty($filters['department_id'])) {
                $employeesQuery->where('department_id', $filters['department_id']);
            }

            $summary['absent'] = max(0, $employeesQuery->count() - (clone $query)->distinct('employee_id')->count('employee_id'));
        }

        return view('attendance.reports.index', [
            'records' => $query->latest('attendance_date')->latest('check_in_at')->paginate(20)->withQueryString(),
            'summary' => $summary,
            'filters' => $filters,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::query()->where('employment_status', 'active')->orderBy('last_name')->get(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function export(AttendanceReportRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $records = $this->reportQuery($filters)->latest('attendance_date')->cursor();
        $filename = "attendance-{$filters['date_from']}-to-{$filters['date_to']}.csv";

        return response()->streamDownload(function () use ($records): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, self::EXPORT_COLUMNS);

            foreach ($records as $record) {
                fputcsv($output, $this->recordRow($record));
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportExcel(AttendanceReportRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $records = $this->reportQuery($filters)->latest('attendance_date')->latest('check_in_at')->get();
        $filename = "attendance-{$filters['date_from']}-to-{$filters['date_to']}.xlsx";
        $lastColumn = Coordinate::stringFromColumnIndex(count(self::EXPORT_COLUMNS));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance');
        $sheet->fromArray(self::EXPORT_COLUMNS, null, 'A1');
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($records as $record) {
            $sheet->fromArray($this->recordRow($record), null, "A{$row}");
            $row++;
        }

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function exportPdf(AttendanceReportRequest $request): Response
    {
        $filters = $request->validated();
        $records = $this->reportQuery($filters)->latest('attendance_date')->latest('check_in_at')->get();
        $filename = "attendance-{$filters['date_from']}-to-{$filters['date_to']}.pdf";

        $pdf = Pdf::loadView('attendance.reports.pdf', [
            'records' => $records,
            'filters' => $filters,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * @return array<int, mixed>
     */
    private function recordRow(AttendanceRecord $record): array
    {
        return [
            $record->attendance_date->toDateString(),
            $record->employee->employee_number,
            $record->employee->full_name,
            $record->employee->department?->name,
            $record->check_in_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('Y-m-d H:i:s'),
            $record->check_out_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('Y-m-d H:i:s'),
            $record->check_in_method,
            $record->checkInBiometricDevice?->name,
            $record->check_out_method,
            $record->checkOutBiometricDevice?->name,
            $record->status,
            $record->late_minutes,
            $record->worked_minutes,
            $record->undertime_minutes,
            $record->overtime_minutes,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function reportQuery(array $filters): Builder
    {
        return AttendanceRecord::query()
            ->with(['employee.user', 'employee.department', 'employee.position', 'officeLocation', 'checkInBiometricDevice', 'checkOutBiometricDevice'])
            ->whereDate('attendance_date', '>=', $filters['date_from'])
            ->whereDate('attendance_date', '<=', $filters['date_to'])
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
            });
    }
}
