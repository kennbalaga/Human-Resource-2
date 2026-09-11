<?php

use App\Models\LeaveType;
use App\Services\ReferenceDataCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The statutory leaves the catalogue was missing. They are only safe to add
 * now: before the classification columns landed, every one of these would have
 * been granted to every employee as a fresh yearly allowance -- ten days of
 * VAWC leave each January, sixty days of special leave for women to the whole
 * workforce -- which is why they were better left out than modelled wrongly.
 *
 * Service thresholds and the working/calendar split are stored as data rather
 * than assumed in code, because they are the details most likely to move with
 * the implementing rules. HR can correct any of them without a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (self::types() as $type) {
            DB::table('leave_types')->insertOrIgnore($type + [
                'category' => LeaveType::CATEGORY_STATUTORY,
                'max_carry_over' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Written with the query builder, so LeaveType::booted never fires and
        // the cached copy would keep serving rows serialised before these
        // columns existed -- for the whole 15-minute TTL. Every leave balance
        // card disappeared off the page that way.
        ReferenceDataCache::forget(LeaveType::class);
    }

    public function down(): void
    {
        $codes = array_column(self::types(), 'code');

        // A type somebody has already filed against is left in place: dropping
        // it would take the leave history with it, and the foreign keys on
        // leave_requests and leave_balances restrict the delete anyway.
        $inUse = DB::table('leave_requests')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereIn('leave_types.code', $codes)
            ->pluck('leave_types.code');

        DB::table('leave_balances')
            ->whereIn('leave_type_id', DB::table('leave_types')->whereIn('code', $codes)->whereNotIn('code', $inUse)->pluck('id'))
            ->delete();

        DB::table('leave_types')->whereIn('code', $codes)->whereNotIn('code', $inUse)->delete();

        ReferenceDataCache::forget(LeaveType::class);
    }

    /** @return array<int, array<string, mixed>> */
    private static function types(): array
    {
        return [
            [
                'code' => 'MATERNITY-ETP',
                'name' => 'Maternity Leave (Miscarriage / Emergency Termination)',
                'description' => 'Para sa miscarriage o emergency termination of pregnancy. Kailangan ng medical certificate.',
                'color' => '#BE185D',
                'annual_entitlement' => 60,
                'accrual_method' => LeaveType::ACCRUAL_PER_EVENT,
                'day_basis' => LeaveType::BASIS_CALENDAR,
                'eligible_gender' => LeaveType::GENDER_FEMALE,
                'min_service_months' => 0,
                'requires_attachment' => true,
            ],
            [
                'code' => 'SOLO-PARENT',
                'name' => 'Parental Leave for Solo Parents',
                'description' => 'Para sa empleyadong may valid Solo Parent ID. Pito (7) araw kada taon, hiwalay sa ibang leave credits.',
                'color' => '#C2410C',
                'annual_entitlement' => 7,
                'accrual_method' => LeaveType::ACCRUAL_ANNUAL,
                'day_basis' => LeaveType::BASIS_WORKING,
                'eligible_gender' => null,
                'min_service_months' => 6,
                'requires_attachment' => true,
            ],
            [
                'code' => 'VAWC',
                'name' => 'VAWC Leave',
                'description' => 'Para sa empleyadong biktima ng violence against women and their children. Sampung (10) araw kada insidente.',
                'color' => '#9D174D',
                'annual_entitlement' => 10,
                'accrual_method' => LeaveType::ACCRUAL_PER_EVENT,
                'day_basis' => LeaveType::BASIS_WORKING,
                'eligible_gender' => null,
                'min_service_months' => 0,
                'requires_attachment' => true,
            ],
            [
                'code' => 'SPECIAL-WOMEN',
                'name' => 'Special Leave for Women (Magna Carta)',
                'description' => 'Para sa gynecological surgery. Hanggang animnapung (60) araw kada operasyon, batay sa medical certificate.',
                'color' => '#A21CAF',
                'annual_entitlement' => 60,
                'accrual_method' => LeaveType::ACCRUAL_PER_EVENT,
                'day_basis' => LeaveType::BASIS_CALENDAR,
                'eligible_gender' => LeaveType::GENDER_FEMALE,
                'min_service_months' => 6,
                'requires_attachment' => true,
            ],
        ];
    }
};
