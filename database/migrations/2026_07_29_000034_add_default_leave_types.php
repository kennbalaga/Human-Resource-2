<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
        });

        $now = now();
        $types = [
            ['code' => 'MATERNITY', 'name' => 'Maternity Leave', 'description' => 'Para sa babaeng empleyadong manganganak. Saklaw ng mga umiiral na batas at SSS benefits.', 'color' => '#DB2777', 'annual_entitlement' => 105, 'max_carry_over' => 0, 'requires_attachment' => true],
            ['code' => 'PATERNITY', 'name' => 'Paternity Leave', 'description' => 'Para sa lalaking empleyado na may asawang nanganak. Saklaw ng Philippine law kung qualified.', 'color' => '#2563EB', 'annual_entitlement' => 7, 'max_carry_over' => 0, 'requires_attachment' => true],
            ['code' => 'SPECIAL-PRIVILEGE', 'name' => 'Special Leave (Special Privilege Leave)', 'description' => 'Para sa personal matters, birthdays, relocation, at iba pang pinapayagan ng kumpanya.', 'color' => '#7C3AED', 'annual_entitlement' => 3, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'BEREAVEMENT', 'name' => 'Bereavement Leave', 'description' => 'Kapag namatay ang immediate family member.', 'color' => '#475569', 'annual_entitlement' => 3, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'STUDY', 'name' => 'Study Leave', 'description' => 'Para sa trainings, seminars, board exams, o postgraduate studies. Karaniwang kailangan ng HR approval.', 'color' => '#0891B2', 'annual_entitlement' => 5, 'max_carry_over' => 0, 'requires_attachment' => true],
            ['code' => 'UNPAID', 'name' => 'Unpaid Leave (Leave Without Pay)', 'description' => 'Kapag wala nang available leave credits o hindi covered ng ibang leave types.', 'color' => '#6B7280', 'annual_entitlement' => 366, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'COMP-OFF', 'name' => 'Compensatory Leave (Comp-Off)', 'description' => 'Kapalit ng approved overtime o work during holidays/rest days, kung pinapayagan ng policy.', 'color' => '#059669', 'annual_entitlement' => 366, 'max_carry_over' => 0, 'requires_attachment' => true],
        ];

        foreach ($types as $type) {
            DB::table('leave_types')->insertOrIgnore($type + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
