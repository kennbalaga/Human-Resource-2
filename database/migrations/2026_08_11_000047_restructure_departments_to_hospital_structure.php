<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RosterDraft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the placeholder department list from
 * 2026_07_29_000035_add_department_categories_and_hospital_departments with
 * this hospital's actual organizational structure. Every step guards on the
 * old row still existing, so this is safe to run both on a fresh install
 * (where that earlier migration just created the old rows) and on an
 * environment where this restructuring was already applied by hand — on the
 * latter, IT/NUR are already gone and every step below becomes a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // --- Rename existing departments in place, preserving their ids
            // so every employee/position FK pointing at them keeps working.
            $renames = [
                'ADMIN' => ['name' => 'Administrative and General Services', 'description' => 'Security, transport, housekeeping, maintenance, and records management.', 'category' => 'administrative'],
                'FIN' => ['name' => 'Finance and Accounting Section', 'description' => 'Budget, billing, claims, and cash operations.', 'category' => 'administrative'],
                'HR' => ['name' => 'Human Resource Management Section', 'description' => 'Personnel hiring, records, payroll, and staff welfare.', 'category' => 'administrative'],
                'PROCUREMENT' => ['name' => 'Materials and Procurement / Supply Section', 'description' => 'Equipment purchasing, property management, and warehousing.', 'category' => 'administrative'],
                'MED' => ['code' => 'DERM', 'name' => 'Department of Dermatology and Leprosy Care', 'description' => "Specialized national reference and training center for skin care and Hansen's disease management.", 'category' => 'clinical'],
                'MED-WARD' => ['code' => 'IM', 'name' => 'Department of Internal Medicine', 'description' => 'Adult general care, sub-specialties, and ward admissions.', 'category' => 'clinical'],
                'SURG-WARD' => ['code' => 'SURG', 'name' => 'Department of Surgery', 'description' => 'General operations, specialized surgical care, and trauma management.', 'category' => 'clinical'],
                'PED-WARD' => ['code' => 'PEDS', 'name' => 'Department of Pediatrics', 'description' => 'Newborn care, child health, and pediatric ward services.', 'category' => 'clinical'],
                'OB-GYN' => ['name' => 'Department of Obstetrics and Gynecology', 'description' => 'Maternal care, prenatal services, and delivery suites.', 'category' => 'clinical'],
                'ER' => ['name' => 'Emergency Department', 'description' => '24/7 acute trauma and urgent care.', 'category' => 'clinical'],
                'OPD' => ['name' => 'Outpatient Department (OPD)', 'description' => 'General and specialty ambulatory clinics.', 'category' => 'clinical'],
                'RADIOLOGY' => ['name' => 'Radiology Department', 'description' => 'X-ray, ultrasound, and imaging diagnostics.', 'category' => 'clinical'],
                'CL' => ['code' => 'LAB', 'name' => 'Laboratory Department', 'description' => 'Pathology, blood bank, and clinical testing services.', 'category' => 'clinical'],
                'LAB' => ['name' => 'Laboratory Department', 'description' => 'Pathology, blood bank, and clinical testing services.', 'category' => 'clinical'],
                'PHARMACY' => ['name' => 'Pharmacy Department', 'description' => 'Medication dispensing and clinical pharmacy programs.', 'category' => 'clinical'],
                'REHAB' => ['code' => 'PT-OT', 'name' => 'Physical Therapy and Occupational Therapy Units', 'description' => 'Rehabilitation and functional restoration services.', 'category' => 'clinical'],
            ];

            foreach ($renames as $oldCode => $changes) {
                Department::query()->where('code', $oldCode)->update($changes + ['is_active' => true]);
            }

            // --- The one genuinely new department.
            Department::query()->updateOrCreate(
                ['code' => 'MEDSOC'],
                [
                    'name' => 'Medical Social Services',
                    'description' => 'Patient financial assistance, case management, and welfare support.',
                    'category' => 'administrative',
                    'is_active' => true,
                ],
            );

            // --- Fold Information Technology into Administrative and
            // General Services, moving its employees and its System
            // Administrator position rather than leaving them behind.
            $admin = Department::where('code', 'ADMIN')->first();
            $it = Department::where('code', 'IT')->first();
            if ($admin !== null && $it !== null) {
                Employee::where('department_id', $it->id)->update(['department_id' => $admin->id]);
                Position::where('department_id', $it->id)->update(['department_id' => $admin->id]);
                Department::where('id', $it->id)->delete();
            }

            // --- Split Nursing Service across the 6 clinical departments,
            // round-robin by employee_number. Positions are department-
            // scoped, so Head Nurse / Staff Nurse need a row per destination
            // department rather than one shared row.
            $nur = Department::where('code', 'NUR')->first();
            if ($nur !== null) {
                $destinationCodes = ['DERM', 'IM', 'SURG', 'PEDS', 'OB-GYN', 'OPD'];
                $destinations = Department::whereIn('code', $destinationCodes)->get()->keyBy('code');

                $headNursePositions = [];
                $staffNursePositions = [];
                foreach ($destinationCodes as $code) {
                    $dept = $destinations[$code];
                    $headNursePositions[$code] = Position::updateOrCreate(
                        ['code' => 'NUR-HEAD-'.$code],
                        ['department_id' => $dept->id, 'title' => 'Head Nurse', 'seniority_rank' => 4, 'is_active' => true],
                    );
                    $staffNursePositions[$code] = Position::updateOrCreate(
                        ['code' => 'NUR-STAFF-'.$code],
                        ['department_id' => $dept->id, 'title' => 'Staff Nurse', 'description' => 'Front-line nursing staff providing direct patient care.', 'seniority_rank' => 2, 'is_active' => true],
                    );
                }

                $headNurseEmployees = Employee::where('department_id', $nur->id)
                    ->whereHas('position', fn ($q) => $q->where('code', 'NUR-HEAD'))
                    ->orderBy('employee_number')
                    ->get();
                $staffNurseEmployees = Employee::where('department_id', $nur->id)
                    ->whereHas('position', fn ($q) => $q->where('code', 'NUR-STAFF'))
                    ->orderBy('employee_number')
                    ->get();

                foreach ($headNurseEmployees as $i => $emp) {
                    $code = $destinationCodes[$i % count($destinationCodes)];
                    $emp->update(['department_id' => $destinations[$code]->id, 'position_id' => $headNursePositions[$code]->id]);
                }
                foreach ($staffNurseEmployees as $i => $emp) {
                    $code = $destinationCodes[$i % count($destinationCodes)];
                    $emp->update(['department_id' => $destinations[$code]->id, 'position_id' => $staffNursePositions[$code]->id]);
                }

                Position::where('code', 'NUR-HEAD')->delete();
                Position::where('code', 'NUR-STAFF')->delete();

                // The department-wide roster_drafts.department_id FK is
                // restrict-on-delete, and RosterDraft isn't soft-deletable;
                // an open draft built for a department that's about to
                // disappear can't be resumed, so it's discarded (not
                // deleted) the same way RosterDraftService::discardDraft()
                // would, keeping the entries on record instead of losing them.
                RosterDraft::where('department_id', $nur->id)->where('status', 'open')->update([
                    'status' => 'discarded',
                    'discarded_at' => now(),
                ]);

                Department::where('id', $nur->id)->delete();
            }

            // --- Delete the remaining departments the new structure doesn't
            // cover. Safe even where they were never populated.
            $deleteCodes = ['ICU', 'OR', 'WARDS', 'DR', 'NICU', 'DIALYSIS', 'BLOOD-BANK', 'RESP-THERAPY', 'NUTRITION', 'BILLING', 'ADMISSIONS', 'HIM', 'WAREHOUSE', 'QA', 'LEGAL', 'HOUSEKEEPING', 'MAINT', 'SECURITY', 'TRANSPORT', 'LAUNDRY', 'CSSD'];
            Department::whereIn('code', $deleteCodes)->delete();
        });
    }

    public function down(): void
    {
        // Renames and merges aren't cleanly reversible (they fold rows that
        // had independent identities and history), and the soft-deleted
        // rows above can be restored individually if ever needed — so this
        // migration doesn't attempt an automatic rollback.
    }
};
