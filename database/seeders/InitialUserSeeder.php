<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\Organization\EmployeeNumberGenerator;
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

        // Each of these four is the founding account for its position, so the
        // employee ID is simply the first of that position's intake. It is
        // composed rather than written out so the year inside the ID always
        // matches the hire date recorded below.
        $hireDate = now();

        $accounts = [
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@hrms.local',
                'role' => 'system-administrator',
                'department' => 'ADMIN',
                'position' => 'SYS-ADMIN',
            ],
            [
                'first_name' => 'HR',
                'last_name' => 'Manager',
                'email' => 'hr.manager@hrms.local',
                'role' => 'hr-manager',
                'department' => 'HR',
                'position' => 'HR-MGR',
            ],
            [
                'first_name' => 'Nursing',
                'last_name' => 'Department Head',
                'email' => 'nursing.head@hrms.local',
                'role' => 'department-head',
                'department' => 'DERM',
                'position' => 'NUR-HEAD-DERM',
            ],
            [
                'first_name' => 'HR',
                'last_name' => 'Employee',
                'email' => 'employee@hrms.local',
                'role' => 'employee',
                'department' => 'HR',
                'position' => 'HR-OFFICER',
                'supervisor' => 'hr.manager@hrms.local',
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
            // Supervisors are named by work email rather than employee ID:
            // IDs are derived from the position and hire year, so the exact
            // sequence on the end of one depends on how the install was seeded.
            $supervisorId = isset($account['supervisor'])
                ? Employee::query()->whereHas('user', fn ($query) => $query->where('email', $account['supervisor']))->value('id')
                : null;

            // Keyed on the user, not the employee ID: an employee ID is a
            // permanent identity that the position-based standardisation
            // migration may have rewritten, so re-seeding an existing install
            // must update that row rather than mint a second one.
            $employee = Employee::query()->firstOrNew(['user_id' => $user->id]);

            if (! $employee->exists) {
                $employee->employee_number = EmployeeNumberGenerator::compose($account['position'], $hireDate->year, 1);
            }

            $employee->fill([
                'department_id' => $department->id,
                'position_id' => $position->id,
                'supervisor_id' => $supervisorId,
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'employment_status' => 'active',
                'hire_date' => $hireDate->toDateString(),
            ])->save();
        }
    }
}
