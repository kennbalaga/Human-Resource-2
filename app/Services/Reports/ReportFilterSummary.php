<?php

namespace App\Services\Reports;

use App\Reports\Report;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The active filters, restated in words.
 *
 * A preview exists to answer "is this the extract I meant?", and a query string
 * cannot answer it: `department_id=7&capture_method=mixed` tells the reader
 * nothing they can check against their intent. The ids are resolved back to the
 * names they were picked by, and anything left at its default is omitted --
 * listing six "All departments" rows would bury the one filter that is actually
 * narrowing the result.
 */
class ReportFilterSummary
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, object>  $departments
     * @param  Collection<int, object>  $employees
     * @param  Collection<int, object>  $leaveTypes
     * @return array<int, array{label: string, value: string}>
     */
    public function for(
        Report $report,
        array $filters,
        Collection $departments,
        Collection $employees,
        Collection $leaveTypes,
    ): array {
        $rows = [[
            'label' => $report->rangeLabel(),
            'value' => Carbon::parse($filters['date_from'])->format('M j, Y')
                .' – '.Carbon::parse($filters['date_to'])->format('M j, Y'),
        ]];

        $named = [
            'department_id' => ['Department', fn ($id) => $departments->firstWhere('id', (int) $id)?->name],
            'employee_id' => ['Employee', fn ($id) => $employees->firstWhere('id', (int) $id)?->full_name],
            'leave_type_id' => ['Leave type', fn ($id) => $leaveTypes->firstWhere('id', (int) $id)?->name],
        ];

        $worded = [
            'status' => 'Status',
            'approval_status' => 'Approval',
            'capture_method' => 'Capture source',
            'leave_status' => 'Leave status',
            'timesheet_status' => 'Timesheet status',
        ];

        foreach ($report->filters() as $key) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (isset($named[$key])) {
                [$label, $resolve] = $named[$key];
                // A filter whose row has since been deleted still has to render
                // as something, or the preview quietly drops a narrowing the
                // export is nonetheless applying.
                $rows[] = ['label' => $label, 'value' => $resolve($value) ?? '#'.$value];

                continue;
            }

            if (isset($worded[$key])) {
                $rows[] = ['label' => $worded[$key], 'value' => str($value)->headline()->toString()];
            }
        }

        return $rows;
    }
}
