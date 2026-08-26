<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ShiftScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // The standard shift templates (Morning, Afternoon, Night, Administrative)
        // are created by the 2026_08_03_000038 migration, not here, since they are
        // protected system templates that must exist before this seeder runs.
        // created_by is required (NOT NULL) but this seeder has no authenticated
        // actor of its own, so demo rows are attributed to the same designated
        // migration actor the provenance backfill uses.
        $migrationActorId = User::query()->where('email', 'admin@hrms.local')->value('id');
        $weekStart = now(config('schedule.timezone'))->startOfWeek(Carbon::MONDAY);
        $seedAssignments = [
            ['employee' => 'SYS-ADMIN-2026-0001', 'shift' => 'ADMIN-0800'],
            ['employee' => 'HR-MGR-2026-0001', 'shift' => 'ADMIN-0800'],
            ['employee' => 'HR-OFFICER-2026-0001', 'shift' => 'ADMIN-0800'],
            ['employee' => 'NUR-HEAD-DERM-2026-0001', 'shift' => 'NIGHT-2200'],
        ];

        foreach ($seedAssignments as $assignment) {
            $employee = Employee::query()->where('employee_number', $assignment['employee'])->first();
            $shift = Shift::query()->where('code', $assignment['shift'])->first();

            if (! $employee || ! $shift) {
                continue;
            }

            RosterWriteContext::allowUnattended(function () use ($employee, $shift, $weekStart, $migrationActorId): void {
                foreach (range(0, 4) as $dayOffset) {
                    ScheduleAssignment::query()->updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'shift_id' => $shift->id,
                            'work_date' => $weekStart->copy()->addDays($dayOffset)->toDateString(),
                        ],
                        ['status' => 'scheduled', 'created_via' => 'legacy', 'created_by' => $migrationActorId],
                    );
                }
            });
        }
    }
}
