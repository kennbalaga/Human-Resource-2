<?php

use App\Models\LeaveType;
use App\Services\ReferenceDataCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some entitlements are not open to the whole workforce but to a standing
 * status HR has verified. Solo parent leave is the one that exists today: the
 * seven days belong to holders of a DSWD solo parent ID, not to everybody.
 *
 * Modelled as the status a type demands rather than as a boolean per type, so
 * a second one does not need another column. The employee side answers it in
 * Employee::hasDesignation, which fails closed on anything it does not know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('requires_designation', 30)->nullable()->after('min_service_months');
        });

        DB::table('leave_types')
            ->where('code', 'SOLO-PARENT')
            ->update(['requires_designation' => LeaveType::DESIGNATION_SOLO_PARENT]);

        // Written with the query builder, so LeaveType::booted never fires and
        // the cache would keep serving rows without the column.
        ReferenceDataCache::forget(LeaveType::class);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('requires_designation');
        });

        ReferenceDataCache::forget(LeaveType::class);
    }
};
