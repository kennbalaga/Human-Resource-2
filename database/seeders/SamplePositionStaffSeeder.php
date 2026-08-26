<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tops up every position with a few more sample employees. Employee numbers
 * are hardcoded (continuing each department's existing sequence) rather than
 * derived from a live count, so re-running this seeder updates the same rows
 * via updateOrCreate instead of appending a fresh batch each time.
 */
class SamplePositionStaffSeeder extends Seeder
{
    public function run(): void
    {
        $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();
        $defaultPassword = app()->environment(['local', 'testing'])
            ? (string) config('workforce.local_employee_default_password')
            : Str::password(40);

        $groups = [
            ['department' => 'ADMIN', 'position' => 'HR-HR', 'title' => 'Payroll', 'staff' => [
                ['employee_number' => 'HR-HR-2026-0001', 'first_name' => 'Elena', 'last_name' => 'Bautista', 'hire_date' => '2026-02-09', 'contact_number' => '09171234501'],
                ['employee_number' => 'HR-HR-2026-0002', 'first_name' => 'Ramon', 'last_name' => 'Villanueva', 'hire_date' => '2026-04-14', 'contact_number' => '09171234502'],
                ['employee_number' => 'HR-HR-2026-0003', 'first_name' => 'Grace', 'last_name' => 'Mendoza', 'hire_date' => '2026-06-22', 'contact_number' => '09171234503'],
            ]],
            ['department' => 'ER', 'position' => 'ER-PHYSICIAN', 'title' => 'ER Physician', 'staff' => [
                ['employee_number' => 'ER-PHYSICIAN-2026-0001', 'first_name' => 'Antonio', 'last_name' => 'Reyes', 'hire_date' => '2026-01-20', 'contact_number' => '09171234504'],
                ['employee_number' => 'ER-PHYSICIAN-2026-0002', 'first_name' => 'Patricia', 'last_name' => 'Gonzales', 'hire_date' => '2026-03-11', 'contact_number' => '09171234505'],
                ['employee_number' => 'ER-PHYSICIAN-2026-0003', 'first_name' => 'Daniel', 'last_name' => 'Aguilar', 'hire_date' => '2026-05-30', 'contact_number' => '09171234506', 'employment_status' => 'on_leave'],
            ]],
            ['department' => 'ER', 'position' => 'ER-RN', 'title' => 'Registered Nurse', 'staff' => [
                ['employee_number' => 'ER-RN-2026-0001', 'first_name' => 'Angelica', 'last_name' => 'Fernandez', 'hire_date' => '2026-02-17', 'contact_number' => '09171234507'],
                ['employee_number' => 'ER-RN-2026-0002', 'first_name' => 'Mark', 'last_name' => 'Villareal', 'hire_date' => '2026-04-28', 'contact_number' => '09171234508'],
                ['employee_number' => 'ER-RN-2026-0003', 'first_name' => 'Jasmine', 'last_name' => 'Navarro', 'hire_date' => '2026-07-09', 'contact_number' => '09171234509'],
            ]],
            ['department' => 'HR', 'position' => 'HR-MGR', 'title' => 'HR Manager', 'staff' => [
                ['employee_number' => 'HR-MGR-2026-0002', 'first_name' => 'Sofia', 'last_name' => 'Ramirez', 'hire_date' => '2026-01-12', 'contact_number' => '09171234510'],
                ['employee_number' => 'HR-MGR-2026-0003', 'first_name' => 'Benjamin', 'last_name' => 'Cruz', 'hire_date' => '2026-03-25', 'contact_number' => '09171234511'],
                ['employee_number' => 'HR-MGR-2026-0004', 'first_name' => 'Camille', 'last_name' => 'Ortiz', 'hire_date' => '2026-06-08', 'contact_number' => '09171234512'],
            ]],
            ['department' => 'HR', 'position' => 'HR-OFFICER', 'title' => 'HR Officer', 'staff' => [
                ['employee_number' => 'HR-OFFICER-2026-0002', 'first_name' => 'Nathaniel', 'last_name' => 'Garcia', 'hire_date' => '2026-02-02', 'contact_number' => '09171234513'],
                ['employee_number' => 'HR-OFFICER-2026-0003', 'first_name' => 'Bianca', 'last_name' => 'Reyes', 'hire_date' => '2026-04-19', 'contact_number' => '09171234514'],
                ['employee_number' => 'HR-OFFICER-2026-0004', 'first_name' => 'Julius', 'last_name' => 'Pascual', 'hire_date' => '2026-07-27', 'contact_number' => '09171234515', 'employment_status' => 'on_leave'],
            ]],
            ['department' => 'ADMIN', 'position' => 'SYS-ADMIN', 'title' => 'System Administrator', 'staff' => [
                ['employee_number' => 'SYS-ADMIN-2026-0002', 'first_name' => 'Kevin', 'last_name' => 'Manalo', 'hire_date' => '2026-01-30', 'contact_number' => '09171234516'],
                ['employee_number' => 'SYS-ADMIN-2026-0003', 'first_name' => 'Andrea', 'last_name' => 'Lopez', 'hire_date' => '2026-05-06', 'contact_number' => '09171234517'],
                ['employee_number' => 'SYS-ADMIN-2026-0004', 'first_name' => 'Francis', 'last_name' => 'Dizon', 'hire_date' => '2026-07-15', 'contact_number' => '09171234518'],
            ]],
            // Nursing Service was later dissolved and split across the
            // clinical departments (see the department-restructure note in
            // OrganizationSeeder) — these land in Dermatology and Leprosy
            // Care, the same default the other nursing seed data uses.
            ['department' => 'DERM', 'position' => 'NUR-HEAD-DERM', 'title' => 'Head Nurse', 'staff' => [
                ['employee_number' => 'NUR-HEAD-DERM-2026-0002', 'first_name' => 'Isabel', 'last_name' => 'Mercado', 'hire_date' => '2026-02-23', 'contact_number' => '09171234519'],
                ['employee_number' => 'NUR-HEAD-DERM-2026-0003', 'first_name' => 'Victor', 'last_name' => 'Aquino', 'hire_date' => '2026-04-05', 'contact_number' => '09171234520'],
                ['employee_number' => 'NUR-HEAD-DERM-2026-0004', 'first_name' => 'Diana', 'last_name' => 'Santiago', 'hire_date' => '2026-06-17', 'contact_number' => '09171234521'],
            ]],
            ['department' => 'DERM', 'position' => 'NUR-STAFF-DERM', 'title' => 'Staff Nurse', 'staff' => [
                ['employee_number' => 'NUR-STAFF-DERM-2026-0005', 'first_name' => 'Alyssa', 'last_name' => 'Morales', 'hire_date' => '2026-01-27', 'contact_number' => '09171234522'],
                ['employee_number' => 'NUR-STAFF-DERM-2026-0006', 'first_name' => 'Christian', 'last_name' => 'Bautista', 'hire_date' => '2026-03-31', 'contact_number' => '09171234523'],
                ['employee_number' => 'NUR-STAFF-DERM-2026-0007', 'first_name' => 'Kimberly', 'last_name' => 'Padilla', 'hire_date' => '2026-08-01', 'contact_number' => '09171234524'],
            ]],
        ];

        foreach ($groups as $group) {
            $department = Department::query()->where('code', $group['department'])->firstOrFail();
            // updateOrCreate rather than firstOrFail: a couple of these
            // (Payroll, ER Physician, ER RN) were only ever created by hand
            // through the app, not by any seeder, so a fresh install has no
            // row to find yet.
            $position = Position::query()->updateOrCreate(
                ['code' => $group['position']],
                ['department_id' => $department->id, 'title' => $group['title'], 'is_active' => true],
            );

            foreach ($group['staff'] as $row) {
                $status = $row['employment_status'] ?? 'active';
                $email = strtolower($row['first_name'].'.'.$row['last_name']).'@hrms.local';
                $email = str_replace(' ', '', $email);

                $user = User::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $row['first_name'].' '.$row['last_name'],
                        'password' => $defaultPassword,
                        'is_active' => in_array($status, ['active', 'on_leave'], true),
                    ],
                );
                $user->roles()->syncWithoutDetaching([$employeeRole->id]);

                // Keyed on the user, not the employee ID: an employee ID is a
                // permanent identity that the position-based standardisation
                // migration may have rewritten, so re-seeding an existing
                // install must update that row rather than mint a second one.
                $employee = Employee::query()->firstOrNew(['user_id' => $user->id]);

                if (! $employee->exists) {
                    $employee->employee_number = $row['employee_number'];
                }

                $employee->fill([
                    'department_id' => $department->id,
                    'position_id' => $position->id,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'employment_status' => $status,
                    'hire_date' => $row['hire_date'],
                    'contact_number' => $row['contact_number'],
                    'address' => 'Manila, Philippines',
                ])->save();
            }
        }
    }
}
