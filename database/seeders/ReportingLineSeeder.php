<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Wires up employees.supervisor_id so the org chart has a real hierarchy to
 * draw. Before this, only one seeded employee out of ~320 had a supervisor,
 * which would have rendered the chart as a few hundred disconnected nodes.
 *
 * The shape is derived from data already in the database (department plus the
 * position's seniority_rank) rather than a hardcoded per-employee list, so it
 * keeps working as the staff seeders change:
 *
 *   System Administrator            <- the single root, never given a supervisor
 *     |- each department's lead     <- highest seniority_rank in that department
 *     |    |- everyone else in that department
 *
 * Runs after the staff seeders and is idempotent: an employee who already has
 * a supervisor is left alone, so re-seeding never rewrites a real assignment.
 */
class ReportingLineSeeder extends Seeder
{
    /**
     * Someone who has left the hospital should not be made anyone's
     * supervisor. Matches the workforce filter used by OrgChartService.
     *
     * @var list<string>
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    private const ROOT_EMPLOYEE_NUMBER = 'SYS-2026-0001';

    public function run(): void
    {
        DB::transaction(function (): void {
            $workforce = Employee::query()
                ->with('position')
                ->whereIn('employment_status', self::WORKFORCE_STATUSES)
                ->get();

            $root = $workforce->firstWhere('employee_number', self::ROOT_EMPLOYEE_NUMBER);

            if ($root === null) {
                return;
            }

            foreach ($workforce->groupBy('department_id') as $department) {
                $lead = $this->leadFor($department, $root);

                foreach ($department as $employee) {
                    // The root reports to nobody, and the department lead is
                    // handled below — assigning a lead to itself here is what
                    // would create the A-supervises-A / A-B-A cycles that the
                    // chart then has to defend against.
                    if ($employee->is($root) || $employee->is($lead)) {
                        continue;
                    }

                    $this->assign($employee, $lead);
                }

                // Every department lead answers to the root, except the root's
                // own department (where the root *is* the lead).
                if (! $lead->is($root)) {
                    $this->assign($lead, $root);
                }
            }
        });
    }

    /**
     * The most senior person in the department, tie-broken on employee number
     * so a re-seed always picks the same person.
     *
     * @param  Collection<int, Employee>  $department
     */
    private function leadFor(Collection $department, Employee $root): Employee
    {
        if ($department->contains(fn (Employee $employee) => $employee->is($root))) {
            return $root;
        }

        return $department
            ->sort(function (Employee $a, Employee $b): int {
                $byRank = ($b->position?->seniority_rank ?? 0) <=> ($a->position?->seniority_rank ?? 0);

                return $byRank !== 0 ? $byRank : ($a->employee_number <=> $b->employee_number);
            })
            ->first();
    }

    private function assign(Employee $employee, Employee $supervisor): void
    {
        if ($employee->supervisor_id !== null || $employee->is($supervisor)) {
            return;
        }

        $employee->update(['supervisor_id' => $supervisor->id]);
    }
}
