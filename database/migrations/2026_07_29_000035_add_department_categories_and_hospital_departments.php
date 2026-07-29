<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('category', 30)->nullable()->index()->after('description');
        });

        DB::table('departments')->whereIn('code', ['MED', 'NUR'])->update(['category' => 'clinical']);
        DB::table('departments')->whereIn('code', ['ADMIN', 'FIN', 'HR', 'IT'])->update(['category' => 'administrative']);

        $now = now();
        $departments = [
            ['code' => 'ER', 'name' => 'Emergency Room', 'category' => 'clinical', 'description' => 'Emergency assessment, stabilization, and treatment.'],
            ['code' => 'ICU', 'name' => 'Intensive Care Unit', 'category' => 'clinical', 'description' => 'Critical care for patients requiring intensive monitoring.'],
            ['code' => 'OR', 'name' => 'Operating Room', 'category' => 'clinical', 'description' => 'Surgical and perioperative services.'],
            ['code' => 'OPD', 'name' => 'Outpatient Department', 'category' => 'clinical', 'description' => 'Consultation and ambulatory care services.'],
            ['code' => 'WARDS', 'name' => 'Inpatient Wards', 'category' => 'clinical', 'description' => 'General inpatient care and ward operations.'],
            ['code' => 'DR', 'name' => 'Delivery Room', 'category' => 'clinical', 'description' => 'Labor and delivery services.'],
            ['code' => 'NICU', 'name' => 'Neonatal Intensive Care Unit', 'category' => 'clinical', 'description' => 'Specialized critical care for newborns.'],
            ['code' => 'PED-WARD', 'name' => 'Pediatric Ward', 'category' => 'clinical', 'description' => 'Inpatient care for pediatric patients.'],
            ['code' => 'MED-WARD', 'name' => 'Medical Ward', 'category' => 'clinical', 'description' => 'Inpatient medical care.'],
            ['code' => 'SURG-WARD', 'name' => 'Surgical Ward', 'category' => 'clinical', 'description' => 'Inpatient surgical care and recovery.'],
            ['code' => 'OB-GYN', 'name' => 'Obstetrics and Gynecology', 'category' => 'clinical', 'description' => 'Women’s health, obstetric, and gynecologic care.'],
            ['code' => 'DIALYSIS', 'name' => 'Dialysis Unit', 'category' => 'clinical', 'description' => 'Renal dialysis services.'],
            ['code' => 'REHAB', 'name' => 'Rehabilitation and Physical Therapy', 'category' => 'clinical', 'description' => 'Rehabilitation and physical therapy services.'],
            ['code' => 'LAB', 'name' => 'Clinical Laboratory', 'category' => 'clinical', 'description' => 'Laboratory diagnostic services.'],
            ['code' => 'RADIOLOGY', 'name' => 'Radiology and Imaging', 'category' => 'clinical', 'description' => 'Diagnostic imaging services.'],
            ['code' => 'PHARMACY', 'name' => 'Pharmacy', 'category' => 'clinical', 'description' => 'Medication management and dispensing.'],
            ['code' => 'BLOOD-BANK', 'name' => 'Blood Bank', 'category' => 'clinical', 'description' => 'Blood collection, storage, and transfusion support.'],
            ['code' => 'RESP-THERAPY', 'name' => 'Respiratory Therapy', 'category' => 'clinical', 'description' => 'Respiratory assessment and therapy services.'],
            ['code' => 'NUTRITION', 'name' => 'Nutrition and Dietetics', 'category' => 'clinical', 'description' => 'Clinical nutrition and dietetic services.'],
            ['code' => 'BILLING', 'name' => 'Billing', 'category' => 'administrative', 'description' => 'Patient billing and account reconciliation.'],
            ['code' => 'ADMISSIONS', 'name' => 'Admissions', 'category' => 'administrative', 'description' => 'Patient registration and admission coordination.'],
            ['code' => 'HIM', 'name' => 'Medical Records and Health Information Management', 'category' => 'administrative', 'description' => 'Health records, privacy, and information management.'],
            ['code' => 'PROCUREMENT', 'name' => 'Procurement and Purchasing', 'category' => 'administrative', 'description' => 'Purchasing and supplier coordination.'],
            ['code' => 'WAREHOUSE', 'name' => 'Supply Chain and Warehouse', 'category' => 'administrative', 'description' => 'Inventory, warehousing, and supply distribution.'],
            ['code' => 'QA', 'name' => 'Quality Assurance', 'category' => 'administrative', 'description' => 'Quality management and continuous improvement.'],
            ['code' => 'LEGAL', 'name' => 'Legal Office', 'category' => 'administrative', 'description' => 'Legal and regulatory support.'],
            ['code' => 'HOUSEKEEPING', 'name' => 'Housekeeping', 'category' => 'support', 'description' => 'Environmental cleaning and sanitation services.'],
            ['code' => 'MAINT', 'name' => 'Maintenance and Engineering', 'category' => 'support', 'description' => 'Facility, equipment, and engineering support.'],
            ['code' => 'SECURITY', 'name' => 'Security', 'category' => 'support', 'description' => 'Facility security and safety support.'],
            ['code' => 'TRANSPORT', 'name' => 'Transport Services', 'category' => 'support', 'description' => 'Patient, staff, and material transport services.'],
            ['code' => 'LAUNDRY', 'name' => 'Laundry', 'category' => 'support', 'description' => 'Linen and laundry operations.'],
            ['code' => 'CSSD', 'name' => 'Central Sterile Supply Department', 'category' => 'support', 'description' => 'Sterile processing and supply support.'],
        ];

        foreach ($departments as $department) {
            DB::table('departments')->insertOrIgnore($department + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
