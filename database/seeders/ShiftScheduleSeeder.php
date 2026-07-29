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
        $shifts = [
            ['code' => 'AM-0700', 'name' => 'Morning Shift', 'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 60, 'color' => '#2F80ED'],
            ['code' => 'DAY-0800', 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'color' => '#176B43'],
            ['code' => 'PM-1500', 'name' => 'Evening Shift', 'start_time' => '15:00', 'end_time' => '23:00', 'break_minutes' => 60, 'color' => '#8B5CF6'],
            ['code' => 'NIGHT-2300', 'name' => 'Night Shift', 'start_time' => '23:00', 'end_time' => '07:00', 'break_minutes' => 60, 'color' => '#334155'],
        ];

        foreach ($shifts as $shift) {
            Shift::query()->updateOrCreate(
                ['code' => $shift['code']],
                $shift + ['is_active' => true],
            );
        }

        $weekStart = now(config('schedule.timezone'))->startOfWeek(Carbon::MONDAY);
        $seedAssignments = [
            ['employee' => 'SYS-2026-0001', 'shift' => 'AM-0700'],
            ['employee' => 'HR-2026-0001', 'shift' => 'DAY-0800'],
            ['employee' => 'HR-2026-0002', 'shift' => 'DAY-0800'],
            ['employee' => 'NUR-2026-0001', 'shift' => 'NIGHT-2300'],
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
