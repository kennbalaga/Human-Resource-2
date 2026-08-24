<?php

namespace App\Services\Organization;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Builds the reporting-line tree from Employee::$supervisor_id.
 *
 * Everything here comes out of one query: the whole visible workforce is
 * loaded once and the tree is assembled in memory by grouping on
 * supervisor_id. That avoids a recursive query per level, and it also means
 * the missing standalone index on `supervisor_id` never matters (the only
 * index covering it today is the composite department_id/employment_status
 * one), so this needs no migration.
 */
class OrgChartService
{
    /**
     * Matches AttendanceOverviewService/ShiftOverviewService: someone on leave
     * is still on the org chart, someone who has left the hospital is not.
     *
     * @var list<string>
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    /**
     * @return array{roots: array<int, array<string, mixed>>, total: int, placed: int, unplaced: int}
     */
    public function tree(): array
    {
        $employees = Employee::query()
            ->with(['department', 'position'])
            ->whereIn('employment_status', self::WORKFORCE_STATUSES)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $visible = $employees->keyBy('id');
        $reportsBySupervisor = $employees->groupBy('supervisor_id');

        // A root is anyone with no supervisor, or whose supervisor is not on
        // the chart (they have left, or are soft-deleted). That second case is
        // why the roots are a collection rather than a single node: dropping
        // those people would make them invisible in the org chart entirely,
        // which for an HR system is a correctness bug, not a simplification.
        $roots = $employees->filter(
            fn (Employee $employee) => $employee->supervisor_id === null
                || ! $visible->has($employee->supervisor_id),
        );

        // Built with foreach rather than ->map(): an arrow function captures by
        // value, so a `&$visited` reference passed through one silently stops
        // propagating and every cycle guard/count below reads empty.
        $visited = [];
        $nodes = [];

        foreach ($roots as $employee) {
            $nodes[] = $this->node($employee, $reportsBySupervisor, $visited);
        }

        return [
            'roots' => $nodes,
            'total' => $employees->count(),
            'placed' => count($visited),
            // Anyone the walk never reached sits in a supervisor cycle that has
            // no root (A supervises B, B supervises A). Nothing creates one
            // today, but supervisor_id is a self-referencing FK with no
            // database-level guard against it, so the count is surfaced rather
            // than the walk hanging on it.
            'unplaced' => $employees->count() - count($visited),
        ];
    }

    /**
     * @param  Collection<int|string, Collection<int, Employee>>  $reportsBySupervisor
     * @param  array<int, true>  $visited
     * @return array<string, mixed>
     */
    private function node(Employee $employee, Collection $reportsBySupervisor, array &$visited): array
    {
        $visited[$employee->id] = true;

        $reports = [];

        foreach ($reportsBySupervisor->get($employee->id, collect()) as $report) {
            // Already visited means this edge closes a cycle. Skipping it is
            // what keeps the recursion finite.
            if (isset($visited[$report->id])) {
                continue;
            }

            $reports[] = $this->node($report, $reportsBySupervisor, $visited);
        }

        $rank = $employee->position?->seniority_rank;

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'employee_number' => $employee->employee_number,
            'position' => $employee->position?->title,
            'seniority_rank' => $rank,
            'seniority_label' => $rank === null ? null : (Position::SENIORITY_RANK_LABELS[$rank] ?? null),
            'department' => $employee->department?->name,
            'department_code' => $employee->department?->code,
            'employment_status' => $employee->employment_status,
            'direct_reports' => count($reports),
            'reports' => $reports,
        ];
    }
}
