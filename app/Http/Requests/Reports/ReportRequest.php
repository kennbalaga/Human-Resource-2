<?php

namespace App\Http\Requests\Reports;

use App\Models\LeaveRequest;
use App\Reports\Report;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One filter contract for every report.
 *
 * Attendance, analytics and the timesheet list each carried their own copy of
 * the same date-range validation, each with its own maximum and its own wording
 * for exceeding it. This is the single version, and it validates against the
 * report actually being run: a filter a report does not declare is dropped
 * rather than silently ignored further down, so a query can never be narrowed
 * by a parameter its own definition knows nothing about.
 */
class ReportRequest extends FormRequest
{
    /** @var array<int, string> */
    private const SUPERVISORY_ROLES = ['system-administrator', 'hr-manager', 'department-head'];

    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(self::SUPERVISORY_ROLES)->isNotEmpty() ?? false;
    }

    public function report(): Report
    {
        $report = app(ReportRegistry::class)->find($this->route('report'));

        abort_if($report === null, 404);

        return $report;
    }

    protected function prepareForValidation(): void
    {
        $today = now()->timezone(config('attendance.default_location.timezone'));

        $this->merge([
            'date_from' => $this->input('date_from', $today->copy()->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', $today->toDateString()),
        ]);
    }

    public function rules(): array
    {
        $maxDays = (int) config('reports.max_days');

        return [
            'date_from' => ['bail', 'required', 'date'],
            'date_to' => [
                'bail',
                'required',
                'date',
                'after_or_equal:date_from',
                function (string $attribute, mixed $value, Closure $fail) use ($maxDays): void {
                    if (! $this->date('date_from') || ! $this->date('date_to')) {
                        return;
                    }

                    if ($this->date('date_from')->diffInDays($this->date('date_to')) > $maxDays) {
                        $fail('The report range may not exceed '.$maxDays.' days.');
                    }
                },
            ],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'string', 'in:present,late'],
            'approval_status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'capture_method' => ['nullable', 'string', 'in:manual,biometric,qr,mixed'],
            'leave_status' => ['nullable', 'string', Rule::in(LeaveRequest::FILTERABLE_STATUSES)],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'timesheet_status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected'],
            'format' => ['nullable', 'string', 'in:'.implode(',', ReportExporter::FORMATS)],
        ];
    }

    /**
     * The validated range plus only the filters this report declares.
     *
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        $allowed = array_merge(['date_from', 'date_to'], $this->report()->filters());

        return array_intersect_key($this->validated(), array_flip($allowed));
    }

    /**
     * The legacy /attendance/reports/export-pdf style routes pin their format
     * as a route default, since the format was part of the path rather than a
     * parameter. The canonical route takes it from the query string.
     */
    /**
     * Not `format()`: Illuminate\Http\Request already defines that for content
     * negotiation, and overriding it with an incompatible signature is a fatal
     * error rather than a shadowed method.
     */
    public function exportFormat(): string
    {
        return $this->route('format') ?? ($this->validated()['format'] ?? 'csv');
    }
}
