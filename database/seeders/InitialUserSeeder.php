<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class InitialUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('INITIAL_USER_PASSWORD', 'ChangeMe123!');

        if (strlen($password) < 12) {
            throw new RuntimeException('INITIAL_USER_PASSWORD must contain at least 12 characters.');
        }

        $accounts = [
            [
                'employee_number' => 'SYS-0001',
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@hrms.local',
                'role' => 'system-administrator',
                'department' => 'IT',
                'position' => 'SYS-ADMIN',
            ],
            [
                'employee_number' => 'HR-0001',
                'first_name' => 'HR',
                'last_name' => 'Manager',
                'email' => 'hr.manager@hrms.local',
                'role' => 'hr-manager',
                'department' => 'HR',
                'position' => 'HR-MGR',
            ],
            [
                'employee_number' => 'NUR-0001',
                'first_name' => 'Nursing',
                'last_name' => 'Department Head',
                'email' => 'nursing.head@hrms.local',
                'role' => 'department-head',
                'department' => 'NUR',
                'position' => 'NUR-HEAD',
            ],
            [
                'employee_number' => 'HR-0002',
                'first_name' => 'HR',
                'last_name' => 'Employee',
                'email' => 'employee@hrms.local',
                'role' => 'employee',
                'department' => 'HR',
                'position' => 'HR-OFFICER',
                'supervisor' => 'HR-0001',
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['first_name'].' '.$account['last_name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                    'is_active' => true,
                ],
            );

            $role = Role::query()->where('slug', $account['role'])->firstOrFail();
            $user->roles()->sync([$role->id]);

            $department = Department::query()->where('code', $account['department'])->firstOrFail();
            $position = Position::query()->where('code', $account['position'])->firstOrFail();
            $supervisorId = isset($account['supervisor'])
                ? Employee::query()->where('employee_number', $account['supervisor'])->value('id')
                : null;

            Employee::query()->updateOrCreate(
                ['employee_number' => $account['employee_number']],
                [
                    'user_id' => $user->id,
                    'department_id' => $department->id,
                    'position_id' => $position->id,
                    'supervisor_id' => $supervisorId,
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'employment_status' => 'active',
                    'hire_date' => now()->toDateString(),
                ],
            );
        }
    }
}
