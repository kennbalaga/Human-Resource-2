<?php

use App\Models\LeaveType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The classification migration zeroed the 366-day placeholders on unpaid leave
 * and comp-off, but left the per-occasion types holding credits they were
 * never owed: 300 employees each carrying an entitlement of 105 maternity days
 * and 7 paternity days, granted by the old seeder as though they were a yearly
 * allowance. 31,500 days of maternity leave nobody had accrued.
 *
 * Inert on the leave page, which reads the entitlement off the type rather than
 * the balance, but the staff dashboard and the employee record both total
 * entitled_days straight out of this table and would have shown every one of
 * them.
 *
 * Only the granted figures are cleared. used_days and pending_days are real
 * history -- leave somebody actually took -- and are left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_balances')
            ->whereIn('leave_type_id', $this->nonAnnualTypeIds())
            ->update(['entitled_days' => 0, 'carried_over_days' => 0]);
    }

    public function down(): void
    {
        // Restores the per-occasion entitlement onto the balance, which is the
        // state this migration exists to correct. Unpaid leave and comp-off are
        // not touched here: the classification migration's own down() puts
        // their 366-day placeholders back.
        foreach (LeaveType::query()->where('accrual_method', LeaveType::ACCRUAL_PER_EVENT)->get() as $type) {
            DB::table('leave_balances')
                ->where('leave_type_id', $type->id)
                ->update(['entitled_days' => $type->annual_entitlement]);
        }
    }

    /** @return Collection<int, int> */
    private function nonAnnualTypeIds()
    {
        return DB::table('leave_types')
            ->where('accrual_method', '!=', LeaveType::ACCRUAL_ANNUAL)
            ->pluck('id');
    }
};
