<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            // Administrative Services & Support Departments
            ['code' => 'ADMIN', 'name' => 'Administrative and General Services', 'category' => 'administrative', 'description' => 'Security, transport, housekeeping, maintenance, and records management.'],
            ['code' => 'FIN', 'name' => 'Finance and Accounting Section', 'category' => 'administrative', 'description' => 'Budget, billing, claims, and cash operations.'],
            ['code' => 'HR', 'name' => 'Human Resource Management Section', 'category' => 'administrative', 'description' => 'Personnel hiring, records, payroll, and staff welfare.'],
            ['code' => 'PROCUREMENT', 'name' => 'Materials and Procurement / Supply Section', 'category' => 'administrative', 'description' => 'Equipment purchasing, property management, and warehousing.'],
            ['code' => 'MEDSOC', 'name' => 'Medical Social Services', 'category' => 'administrative', 'description' => 'Patient financial assistance, case management, and welfare support.'],
            // Clinical and Medical Departments
            ['code' => 'DERM', 'name' => 'Department of Dermatology and Leprosy Care', 'category' => 'clinical', 'description' => "Specialized national reference and training center for skin care and Hansen's disease management."],
            ['code' => 'IM', 'name' => 'Department of Internal Medicine', 'category' => 'clinical', 'description' => 'Adult general care, sub-specialties, and ward admissions.'],
            ['code' => 'SURG', 'name' => 'Department of Surgery', 'category' => 'clinical', 'description' => 'General operations, specialized surgical care, and trauma management.'],
            ['code' => 'PEDS', 'name' => 'Department of Pediatrics', 'category' => 'clinical', 'description' => 'Newborn care, child health, and pediatric ward services.'],
            ['code' => 'OB-GYN', 'name' => 'Department of Obstetrics and Gynecology', 'category' => 'clinical', 'description' => 'Maternal care, prenatal services, and delivery suites.'],
            ['code' => 'ER', 'name' => 'Emergency Department', 'category' => 'clinical', 'description' => '24/7 acute trauma and urgent care.'],
            ['code' => 'OPD', 'name' => 'Outpatient Department (OPD)', 'category' => 'clinical', 'description' => 'General and specialty ambulatory clinics.'],
            // Ancillary and Diagnostic Units
            ['code' => 'RADIOLOGY', 'name' => 'Radiology Department', 'category' => 'clinical', 'description' => 'X-ray, ultrasound, and imaging diagnostics.'],
            ['code' => 'LAB', 'name' => 'Laboratory Department', 'category' => 'clinical', 'description' => 'Pathology, blood bank, and clinical testing services.'],
            ['code' => 'PHARMACY', 'name' => 'Pharmacy Department', 'category' => 'clinical', 'description' => 'Medication dispensing and clinical pharmacy programs.'],
            ['code' => 'PT-OT', 'name' => 'Physical Therapy and Occupational Therapy Units', 'category' => 'clinical', 'description' => 'Rehabilitation and functional restoration services.'],
        ];

        foreach ($departments as $department) {
            Department::query()->updateOrCreate(
                ['code' => $department['code']],
                $department + ['is_active' => true],
            );
        }

        $positions = [
            ['department' => 'ADMIN', 'code' => 'SYS-ADMIN', 'title' => 'System Administrator'],
            ['department' => 'HR', 'code' => 'HR-MGR', 'title' => 'HR Manager'],
            ['department' => 'HR', 'code' => 'HR-OFFICER', 'title' => 'HR Officer'],
        ];

        foreach ($positions as $position) {
            $departmentId = Department::query()
                ->where('code', $position['department'])
                ->firstOrFail()
                ->id;

            Position::query()->updateOrCreate(
                ['code' => $position['code']],
                [
                    'department_id' => $departmentId,
                    'title' => $position['title'],
                    'is_active' => true,
                ],
            );
        }

        // Nursing no longer has a single central department: a hospital-wide
        // restructure split Nursing Service across the clinical departments,
        // so Head Nurse and Staff Nurse are positions scoped to each of them
        // rather than one shared pair. NursingStaffSeeder distributes actual
        // staff across these round-robin.
        $nursingDepartmentCodes = ['DERM', 'IM', 'SURG', 'PEDS', 'OB-GYN', 'OPD'];
        foreach ($nursingDepartmentCodes as $code) {
            $departmentId = Department::query()->where('code', $code)->firstOrFail()->id;

            Position::query()->updateOrCreate(
                ['code' => 'NUR-HEAD-'.$code],
                ['department_id' => $departmentId, 'title' => 'Head Nurse', 'seniority_rank' => 4, 'is_active' => true],
            );
            Position::query()->updateOrCreate(
                ['code' => 'NUR-STAFF-'.$code],
                [
                    'department_id' => $departmentId,
                    'title' => 'Staff Nurse',
                    'description' => 'Front-line nursing staff providing direct patient care.',
                    'seniority_rank' => 2,
                    'is_active' => true,
                ],
            );
        }
    }
}
