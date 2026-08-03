<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ShiftScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // The standard shift templates (Morning, Afternoon, Night, Administrative)
        // are created by the 2026_08_03_000038 migration, not here, since they are
        // protected system templates that must exist before this seeder runs.
        $weekStart = now(config('schedule.timezone'))->startOfWeek(Carbon::MONDAY);
        $seedAssignments = [
            ['employee' => 'SYS-2026-0001', 'shift' => 'ADMIN-0800'],
            ['employee' => 'HR-2026-0001', 'shift' => 'ADMIN-0800'],
            ['employee' => 'HR-2026-0002', 'shift' => 'ADMIN-0800'],
            ['employee' => 'NUR-2026-0001', 'shift' => 'NIGHT-2200'],
        ];

        foreach ($seedAssignments as $assignment) {
            $employee = Employee::query()->where('employee_number', $assignment['employee'])->first();
            $shift = Shift::query()->where('code', $assignment['shift'])->first();

            if (! $employee || ! $shift) {
                continue;
            }

            foreach (range(0, 4) as $dayOffset) {
                ScheduleAssignment::query()->updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'shift_id' => $shift->id,
                        'work_date' => $weekStart->copy()->addDays($dayOffset)->toDateString(),
                    ],
                    ['status' => 'scheduled'],
                );
            }
        }
    }
}
