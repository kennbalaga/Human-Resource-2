<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NursingStaffSeeder extends Seeder
{
    /**
     * Nursing Service was later dissolved and its staff split evenly across
     * the clinical departments (see OrganizationSeeder), so these round-robin
     * across the same 6 departments rather than landing under one central
     * nursing department.
     */
    private const DEPARTMENT_CODES = ['DERM', 'IM', 'SURG', 'PEDS', 'OB-GYN', 'OPD'];

    public function run(): void
    {
        $departments = Department::query()->whereIn('code', self::DEPARTMENT_CODES)->get()->keyBy('code');
        $positions = Position::query()->whereIn('code', array_map(fn ($code) => 'NUR-STAFF-'.$code, self::DEPARTMENT_CODES))->get()->keyBy('code');

        $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();

        $staff = [
            ['employee_number' => 'NUR-2026-0002', 'first_name' => 'Luz', 'last_name' => 'Santos', 'employment_status' => 'active', 'hire_date' => '2026-04-03', 'contact_number' => '09194166432', 'email' => 'luz.santos@hrms.local'],
            ['employee_number' => 'NUR-2026-0003', 'first_name' => 'Manuel', 'last_name' => 'Ocampo', 'employment_status' => 'active', 'hire_date' => '2026-03-10', 'contact_number' => '09119514964', 'email' => 'manuel.ocampo@hrms.local'],
            ['employee_number' => 'NUR-2026-0004', 'first_name' => 'Carlos', 'last_name' => 'Ramos', 'employment_status' => 'active', 'hire_date' => '2026-05-26', 'contact_number' => '09510504415', 'email' => 'carlos.ramos@hrms.local'],
            ['employee_number' => 'NUR-2026-0005', 'first_name' => 'Cristina', 'last_name' => 'Torres', 'employment_status' => 'active', 'hire_date' => '2026-04-25', 'contact_number' => '09998966486', 'email' => 'cristina.torres@hrms.local'],
            ['employee_number' => 'NUR-2026-0006', 'first_name' => 'Roberto', 'last_name' => 'Ramos', 'employment_status' => 'active', 'hire_date' => '2026-05-06', 'contact_number' => '09355438546', 'email' => 'roberto.ramos@hrms.local'],
            ['employee_number' => 'NUR-2026-0007', 'first_name' => 'Luz', 'last_name' => 'Cruz', 'employment_status' => 'active', 'hire_date' => '2026-05-05', 'contact_number' => '09385658391', 'email' => 'luz.cruz@hrms.local'],
            ['employee_number' => 'NUR-2026-0008', 'first_name' => 'Josefina', 'last_name' => 'Domingo', 'employment_status' => 'active', 'hire_date' => '2026-07-03', 'contact_number' => '09449409725', 'email' => 'josefina.domingo@hrms.local'],
            ['employee_number' => 'NUR-2026-0009', 'first_name' => 'Jose', 'last_name' => 'Domingo', 'employment_status' => 'active', 'hire_date' => '2026-01-16', 'contact_number' => '09733608625', 'email' => 'jose.domingo@hrms.local'],
            ['employee_number' => 'NUR-2026-0010', 'first_name' => 'Cristina', 'last_name' => 'Flores', 'employment_status' => 'active', 'hire_date' => '2026-08-18', 'contact_number' => '09187581543', 'email' => 'cristina.flores@hrms.local'],
            ['employee_number' => 'NUR-2026-0011', 'first_name' => 'Maria', 'last_name' => 'Castillo', 'employment_status' => 'active', 'hire_date' => '2026-03-08', 'contact_number' => '09503774837', 'email' => 'maria.castillo@hrms.local'],
            ['employee_number' => 'NUR-2026-0012', 'first_name' => 'Corazon', 'last_name' => 'Cruz', 'employment_status' => 'active', 'hire_date' => '2026-05-13', 'contact_number' => '09399212033', 'email' => 'corazon.cruz@hrms.local'],
            ['employee_number' => 'NUR-2026-0013', 'first_name' => 'Miguel', 'last_name' => 'Del Rosario', 'employment_status' => 'on_leave', 'hire_date' => '2026-03-20', 'contact_number' => '09570505117', 'email' => 'miguel.delrosario@hrms.local'],
            ['employee_number' => 'NUR-2026-0014', 'first_name' => 'Carmen', 'last_name' => 'Del Rosario', 'employment_status' => 'active', 'hire_date' => '2026-01-10', 'contact_number' => '09312446734', 'email' => 'carmen.delrosario@hrms.local'],
            ['employee_number' => 'NUR-2026-0015', 'first_name' => 'Ricardo', 'last_name' => 'Salazar', 'employment_status' => 'active', 'hire_date' => '2026-05-06', 'contact_number' => '09977771275', 'email' => 'ricardo.salazar@hrms.local'],
            ['employee_number' => 'NUR-2026-0016', 'first_name' => 'Teresa', 'last_name' => 'Domingo', 'employment_status' => 'active', 'hire_date' => '2026-07-07', 'contact_number' => '09727311179', 'email' => 'teresa.domingo@hrms.local'],
            ['employee_number' => 'NUR-2026-0017', 'first_name' => 'Carmen', 'last_name' => 'Flores', 'employment_status' => 'active', 'hire_date' => '2026-02-20', 'contact_number' => '09878258608', 'email' => 'carmen.flores@hrms.local'],
            ['employee_number' => 'NUR-2026-0018', 'first_name' => 'Cristina', 'last_name' => 'Cruz', 'employment_status' => 'on_leave', 'hire_date' => '2026-03-15', 'contact_number' => '09372852969', 'email' => 'cristina.cruz@hrms.local'],
            ['employee_number' => 'NUR-2026-0019', 'first_name' => 'Cristina', 'last_name' => 'Domingo', 'employment_status' => 'active', 'hire_date' => '2026-02-18', 'contact_number' => '09238903370', 'email' => 'cristina.domingo@hrms.local'],
            ['employee_number' => 'NUR-2026-0020', 'first_name' => 'Pedro', 'last_name' => 'Aquino', 'employment_status' => 'active', 'hire_date' => '2026-08-15', 'contact_number' => '09967094505', 'email' => 'pedro.aquino@hrms.local'],
            ['employee_number' => 'NUR-2026-0021', 'first_name' => 'Rosa', 'last_name' => 'Cruz', 'employment_status' => 'active', 'hire_date' => '2026-08-06', 'contact_number' => '09278259671', 'email' => 'rosa.cruz@hrms.local'],
        ];

        $defaultPassword = app()->environment(['local', 'testing'])
            ? (string) config('workforce.local_employee_default_password')
            : Str::password(40);

        foreach ($staff as $i => $row) {
            $code = self::DEPARTMENT_CODES[$i % count(self::DEPARTMENT_CODES)];
            $department = $departments[$code];
            $position = $positions['NUR-STAFF-'.$code];

            $isActiveAccount = in_array($row['employment_status'], ['active', 'on_leave'], true);

            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['first_name'].' '.$row['last_name'],
                    'password' => $defaultPassword,
                    'is_active' => $isActiveAccount,
                ],
            );
            $user->roles()->syncWithoutDetaching([$employeeRole->id]);

            Employee::query()->updateOrCreate(
                ['employee_number' => $row['employee_number']],
                [
                    'user_id' => $user->id,
                    'department_id' => $department->id,
                    'position_id' => $position->id,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'employment_status' => $row['employment_status'],
                    'hire_date' => $row['hire_date'],
                    'contact_number' => $row['contact_number'],
                    'address' => 'Manila, Philippines',
                ],
            );
        }
    }
}
