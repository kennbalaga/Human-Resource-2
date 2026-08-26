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
 * The 7 departments the hospital restructure (see OrganizationSeeder) left
 * with no positions or staff at all: Finance and Accounting, Materials and
 * Procurement/Supply, Medical Social Services, Laboratory, Pharmacy,
 * Physical/Occupational Therapy, and Radiology. Each gets a head-level and a
 * staff-level position, and 3 sample employees per position, matching the
 * pattern SamplePositionStaffSeeder used for the departments that already
 * had at least one position.
 */
class EmptyDepartmentStaffSeeder extends Seeder
{
    public function run(): void
    {
        $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();
        $defaultPassword = app()->environment(['local', 'testing'])
            ? (string) config('workforce.local_employee_default_password')
            : Str::password(40);

        $groups = [
            ['department' => 'FIN', 'position' => 'FIN-MGR', 'title' => 'Finance Manager', 'rank' => 4, 'description' => 'Oversees budgeting, billing, claims processing, and cash operations.', 'staff' => [
                ['employee_number' => 'FIN-MGR-2026-0001', 'first_name' => 'Alexander', 'last_name' => 'Marquez', 'hire_date' => '2026-01-19', 'contact_number' => '09171234525'],
                ['employee_number' => 'FIN-MGR-2026-0002', 'first_name' => 'Regina', 'last_name' => 'Concepcion', 'hire_date' => '2026-03-22', 'contact_number' => '09171234526'],
                ['employee_number' => 'FIN-MGR-2026-0003', 'first_name' => 'Miguel', 'last_name' => 'Santos', 'hire_date' => '2026-06-11', 'contact_number' => '09171234527'],
            ]],
            ['department' => 'FIN', 'position' => 'FIN-ACCT', 'title' => 'Accounting Officer', 'rank' => 2, 'description' => 'Handles day-to-day accounting, billing, and cash transactions.', 'staff' => [
                ['employee_number' => 'FIN-ACCT-2026-0001', 'first_name' => 'Cecilia', 'last_name' => 'Bonifacio', 'hire_date' => '2026-02-14', 'contact_number' => '09171234528'],
                ['employee_number' => 'FIN-ACCT-2026-0002', 'first_name' => 'Rodrigo', 'last_name' => 'Espino', 'hire_date' => '2026-05-02', 'contact_number' => '09171234529'],
                ['employee_number' => 'FIN-ACCT-2026-0003', 'first_name' => 'Vanessa', 'last_name' => 'Tolentino', 'hire_date' => '2026-07-21', 'contact_number' => '09171234530', 'employment_status' => 'on_leave'],
            ]],
            ['department' => 'PROCUREMENT', 'position' => 'PROC-MGR', 'title' => 'Procurement Manager', 'rank' => 4, 'description' => 'Oversees equipment purchasing and supplier coordination.', 'staff' => [
                ['employee_number' => 'PROC-MGR-2026-0001', 'first_name' => 'Edgar', 'last_name' => 'Rivera', 'hire_date' => '2026-01-27', 'contact_number' => '09171234531'],
                ['employee_number' => 'PROC-MGR-2026-0002', 'first_name' => 'Marites', 'last_name' => 'Gutierrez', 'hire_date' => '2026-04-09', 'contact_number' => '09171234532'],
                ['employee_number' => 'PROC-MGR-2026-0003', 'first_name' => 'Samuel', 'last_name' => 'Yap', 'hire_date' => '2026-06-28', 'contact_number' => '09171234533'],
            ]],
            ['department' => 'PROCUREMENT', 'position' => 'PROC-WH', 'title' => 'Warehouse Officer', 'rank' => 2, 'description' => 'Manages property, inventory, and warehousing operations.', 'staff' => [
                ['employee_number' => 'PROC-WH-2026-0001', 'first_name' => 'Precious', 'last_name' => 'Umali', 'hire_date' => '2026-02-03', 'contact_number' => '09171234534'],
                ['employee_number' => 'PROC-WH-2026-0002', 'first_name' => 'Bryan', 'last_name' => 'Ferrer', 'hire_date' => '2026-05-17', 'contact_number' => '09171234535'],
                ['employee_number' => 'PROC-WH-2026-0003', 'first_name' => 'Loida', 'last_name' => 'Sarmiento', 'hire_date' => '2026-07-30', 'contact_number' => '09171234536'],
            ]],
            ['department' => 'MEDSOC', 'position' => 'MEDSOC-SUP', 'title' => 'Medical Social Work Supervisor', 'rank' => 4, 'description' => 'Oversees patient financial assistance and case management programs.', 'staff' => [
                ['employee_number' => 'MEDSOC-SUP-2026-0001', 'first_name' => 'Rowena', 'last_name' => 'Castro', 'hire_date' => '2026-01-13', 'contact_number' => '09171234537'],
                ['employee_number' => 'MEDSOC-SUP-2026-0002', 'first_name' => 'Emmanuel', 'last_name' => 'Bautista', 'hire_date' => '2026-03-29', 'contact_number' => '09171234538'],
                ['employee_number' => 'MEDSOC-SUP-2026-0003', 'first_name' => 'Divina', 'last_name' => 'Marasigan', 'hire_date' => '2026-06-05', 'contact_number' => '09171234539'],
            ]],
            ['department' => 'MEDSOC', 'position' => 'MEDSOC-WORKER', 'title' => 'Medical Social Worker', 'rank' => 2, 'description' => 'Provides case management and welfare support to patients.', 'staff' => [
                ['employee_number' => 'MEDSOC-WORKER-2026-0001', 'first_name' => 'Gerald', 'last_name' => 'Salonga', 'hire_date' => '2026-02-21', 'contact_number' => '09171234540'],
                ['employee_number' => 'MEDSOC-WORKER-2026-0002', 'first_name' => 'Fe', 'last_name' => 'Villaflor', 'hire_date' => '2026-04-24', 'contact_number' => '09171234541', 'employment_status' => 'on_leave'],
                ['employee_number' => 'MEDSOC-WORKER-2026-0003', 'first_name' => 'Noel', 'last_name' => 'Abellana', 'hire_date' => '2026-08-02', 'contact_number' => '09171234542'],
            ]],
            ['department' => 'LAB', 'position' => 'LAB-CHIEF', 'title' => 'Chief Medical Technologist', 'rank' => 4, 'description' => 'Oversees pathology, blood bank, and clinical testing operations.', 'staff' => [
                ['employee_number' => 'LAB-CHIEF-2026-0001', 'first_name' => 'Arnold', 'last_name' => 'Peralta', 'hire_date' => '2026-01-08', 'contact_number' => '09171234543'],
                ['employee_number' => 'LAB-CHIEF-2026-0002', 'first_name' => 'Ligaya', 'last_name' => 'Rosales', 'hire_date' => '2026-03-17', 'contact_number' => '09171234544'],
                ['employee_number' => 'LAB-CHIEF-2026-0003', 'first_name' => 'Wilfredo', 'last_name' => 'Nepomuceno', 'hire_date' => '2026-06-20', 'contact_number' => '09171234545'],
            ]],
            ['department' => 'LAB', 'position' => 'LAB-MEDTECH', 'title' => 'Medical Technologist', 'rank' => 2, 'description' => 'Performs clinical laboratory and blood bank testing.', 'staff' => [
                ['employee_number' => 'LAB-MEDTECH-2026-0001', 'first_name' => 'Charmaine', 'last_name' => 'Dungca', 'hire_date' => '2026-02-11', 'contact_number' => '09171234546'],
                ['employee_number' => 'LAB-MEDTECH-2026-0002', 'first_name' => 'Reynaldo', 'last_name' => 'Gatchalian', 'hire_date' => '2026-05-09', 'contact_number' => '09171234547'],
                ['employee_number' => 'LAB-MEDTECH-2026-0003', 'first_name' => 'Michelle', 'last_name' => 'Buenaventura', 'hire_date' => '2026-07-24', 'contact_number' => '09171234548'],
            ]],
            ['department' => 'PHARMACY', 'position' => 'PHARM-CHIEF', 'title' => 'Chief Pharmacist', 'rank' => 4, 'description' => 'Oversees medication dispensing and clinical pharmacy programs.', 'staff' => [
                ['employee_number' => 'PHARM-CHIEF-2026-0001', 'first_name' => 'Ferdinand', 'last_name' => 'Lacson', 'hire_date' => '2026-01-22', 'contact_number' => '09171234549'],
                ['employee_number' => 'PHARM-CHIEF-2026-0002', 'first_name' => 'Susan', 'last_name' => 'Manalastas', 'hire_date' => '2026-04-01', 'contact_number' => '09171234550'],
                ['employee_number' => 'PHARM-CHIEF-2026-0003', 'first_name' => 'Leo', 'last_name' => 'Valderrama', 'hire_date' => '2026-06-26', 'contact_number' => '09171234551'],
            ]],
            ['department' => 'PHARMACY', 'position' => 'PHARM-PHARMACIST', 'title' => 'Pharmacist', 'rank' => 2, 'description' => 'Dispenses medication and supports clinical pharmacy services.', 'staff' => [
                ['employee_number' => 'PHARM-PHARMACIST-2026-0001', 'first_name' => 'Katrina', 'last_name' => 'Cabrera', 'hire_date' => '2026-02-07', 'contact_number' => '09171234552'],
                ['employee_number' => 'PHARM-PHARMACIST-2026-0002', 'first_name' => 'Dennis', 'last_name' => 'Ilagan', 'hire_date' => '2026-05-23', 'contact_number' => '09171234553'],
                ['employee_number' => 'PHARM-PHARMACIST-2026-0003', 'first_name' => 'Marilou', 'last_name' => 'Panganiban', 'hire_date' => '2026-08-04', 'contact_number' => '09171234554'],
            ]],
            ['department' => 'PT-OT', 'position' => 'PTOT-SUP', 'title' => 'Rehabilitation Services Supervisor', 'rank' => 4, 'description' => 'Oversees physical and occupational therapy programs.', 'staff' => [
                ['employee_number' => 'PTOT-SUP-2026-0001', 'first_name' => 'Oscar', 'last_name' => 'Trinidad', 'hire_date' => '2026-01-16', 'contact_number' => '09171234555'],
                ['employee_number' => 'PTOT-SUP-2026-0002', 'first_name' => 'Belinda', 'last_name' => 'Macatangay', 'hire_date' => '2026-03-26', 'contact_number' => '09171234556'],
                ['employee_number' => 'PTOT-SUP-2026-0003', 'first_name' => 'Ronald', 'last_name' => 'Sison', 'hire_date' => '2026-06-13', 'contact_number' => '09171234557'],
            ]],
            ['department' => 'PT-OT', 'position' => 'PTOT-THERAPIST', 'title' => 'Physical Therapist', 'rank' => 2, 'description' => 'Provides rehabilitation and functional restoration therapy.', 'staff' => [
                ['employee_number' => 'PTOT-THERAPIST-2026-0001', 'first_name' => 'Cherry', 'last_name' => 'Malabanan', 'hire_date' => '2026-02-18', 'contact_number' => '09171234558'],
                ['employee_number' => 'PTOT-THERAPIST-2026-0002', 'first_name' => 'Timothy', 'last_name' => 'Ocampo', 'hire_date' => '2026-04-30', 'contact_number' => '09171234559', 'employment_status' => 'on_leave'],
                ['employee_number' => 'PTOT-THERAPIST-2026-0003', 'first_name' => 'Josephine', 'last_name' => 'Aranda', 'hire_date' => '2026-07-11', 'contact_number' => '09171234560'],
            ]],
            ['department' => 'RADIOLOGY', 'position' => 'RAD-RADIOLOGIST', 'title' => 'Radiologist', 'rank' => 4, 'description' => 'Oversees imaging diagnostics and interpretation.', 'staff' => [
                ['employee_number' => 'RAD-RADIOLOGIST-2026-0001', 'first_name' => 'Adrian', 'last_name' => 'Corpuz', 'hire_date' => '2026-01-24', 'contact_number' => '09171234561'],
                ['employee_number' => 'RAD-RADIOLOGIST-2026-0002', 'first_name' => 'Estrella', 'last_name' => 'Villagomez', 'hire_date' => '2026-04-12', 'contact_number' => '09171234562'],
                ['employee_number' => 'RAD-RADIOLOGIST-2026-0003', 'first_name' => 'Gilbert', 'last_name' => 'Nazareno', 'hire_date' => '2026-06-29', 'contact_number' => '09171234563'],
            ]],
            ['department' => 'RADIOLOGY', 'position' => 'RAD-TECH', 'title' => 'Radiologic Technologist', 'rank' => 2, 'description' => 'Performs X-ray, ultrasound, and imaging procedures.', 'staff' => [
                ['employee_number' => 'RAD-TECH-2026-0001', 'first_name' => 'Angeline', 'last_name' => 'Roque', 'hire_date' => '2026-02-26', 'contact_number' => '09171234564'],
                ['employee_number' => 'RAD-TECH-2026-0002', 'first_name' => 'Bartolome', 'last_name' => 'Ilustre', 'hire_date' => '2026-05-14', 'contact_number' => '09171234565'],
                ['employee_number' => 'RAD-TECH-2026-0003', 'first_name' => 'Karen', 'last_name' => 'Mangubat', 'hire_date' => '2026-07-19', 'contact_number' => '09171234566'],
            ]],
        ];

        foreach ($groups as $group) {
            $department = Department::query()->where('code', $group['department'])->firstOrFail();
            $position = Position::query()->updateOrCreate(
                ['code' => $group['position']],
                [
                    'department_id' => $department->id,
                    'title' => $group['title'],
                    'description' => $group['description'],
                    'seniority_rank' => $group['rank'],
                    'is_active' => true,
                ],
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
