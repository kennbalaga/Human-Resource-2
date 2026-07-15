<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveManagementSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'VAC', 'name' => 'Vacation Leave', 'color' => '#2F80ED', 'annual_entitlement' => 15, 'max_carry_over' => 5, 'requires_attachment' => false],
            ['code' => 'SICK', 'name' => 'Sick Leave', 'color' => '#D6455D', 'annual_entitlement' => 15, 'max_carry_over' => 5, 'requires_attachment' => true],
            ['code' => 'EMER', 'name' => 'Emergency Leave', 'color' => '#D98B14', 'annual_entitlement' => 5, 'max_carry_over' => 0, 'requires_attachment' => false],
        ];

        foreach ($types as $data) {
            $type = LeaveType::query()->updateOrCreate(['code' => $data['code']], $data + ['is_active' => true]);
            Employee::query()->where('employment_status', 'active')->each(function (Employee $employee) use ($type): void {
                LeaveBalance::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => now()->year],
                    ['entitled_days' => $type->annual_entitlement],
                );
            });
        }
    }
}
