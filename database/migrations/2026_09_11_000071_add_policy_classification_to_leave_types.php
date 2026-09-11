<?php

use App\Models\LeaveType;
use App\Services\ReferenceDataCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until now every leave type was a yearly allowance with a working-day count
 * and no entry conditions, because that is all the table could describe. That
 * is true of vacation leave and false of every statutory leave: maternity is
 * 105 calendar days per childbirth, not 105 working days every January, and
 * nothing stopped a male employee from filing it.
 *
 * These columns let a type say which of those it is, so the service layer can
 * stop guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('category', 20)->default(LeaveType::CATEGORY_COMPANY)->after('description');
            $table->string('accrual_method', 20)->default(LeaveType::ACCRUAL_ANNUAL)->after('annual_entitlement');
            $table->string('day_basis', 10)->default(LeaveType::BASIS_WORKING)->after('accrual_method');
            $table->string('eligible_gender', 10)->nullable()->after('requires_attachment');
            $table->unsignedSmallInteger('min_service_months')->default(0)->after('eligible_gender');
            $table->boolean('satisfies_sil')->default(false)->after('min_service_months');
        });

        foreach (self::classifications() as $code => $attributes) {
            DB::table('leave_types')->where('code', $code)->update($attributes);
        }

        // Unpaid leave and comp-off carried an entitlement of 366 purely to
        // mean "no fixed cap" -- the hack that once had the balance tile
        // advertising 890 days. accrual_method says it properly now, so the
        // placeholder credits can go.
        $uncapped = DB::table('leave_types')->whereIn('code', ['UNPAID', 'COMP-OFF'])->pluck('id');

        DB::table('leave_types')->whereIn('id', $uncapped)->update(['annual_entitlement' => 0]);
        DB::table('leave_balances')->whereIn('leave_type_id', $uncapped)->update(['entitled_days' => 0]);

        // Written with the query builder, so LeaveType::booted never fires and
        // the cached copy would keep serving rows serialised before these
        // columns existed -- for the whole 15-minute TTL. Every leave balance
        // card disappeared off the page that way.
        ReferenceDataCache::forget(LeaveType::class);
    }

    public function down(): void
    {
        $uncapped = DB::table('leave_types')->whereIn('code', ['UNPAID', 'COMP-OFF'])->pluck('id');

        DB::table('leave_types')->whereIn('id', $uncapped)->update(['annual_entitlement' => 366]);
        DB::table('leave_balances')->whereIn('leave_type_id', $uncapped)->update(['entitled_days' => 366]);

        ReferenceDataCache::forget(LeaveType::class);

        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn([
                'category',
                'accrual_method',
                'day_basis',
                'eligible_gender',
                'min_service_months',
                'satisfies_sil',
            ]);
        });
    }

    /**
     * The seeded types, classified. Anything an installation added by hand
     * keeps the column defaults: a company annual allowance counted in working
     * days, which is what every type behaved as before this migration.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function classifications(): array
    {
        return [
            // The Labour Code excludes employees already enjoying at least five
            // days of paid vacation from the service incentive leave
            // provision, so the 15-day allowance already discharges it. That is
            // recorded here rather than as a separate five-day SIL type, which
            // would hand covered employees five days they are not owed.
            'VAC' => ['satisfies_sil' => true],
            'MATERNITY' => [
                'category' => LeaveType::CATEGORY_STATUTORY,
                'accrual_method' => LeaveType::ACCRUAL_PER_EVENT,
                'day_basis' => LeaveType::BASIS_CALENDAR,
                'eligible_gender' => LeaveType::GENDER_FEMALE,
            ],
            'PATERNITY' => [
                'category' => LeaveType::CATEGORY_STATUTORY,
                'accrual_method' => LeaveType::ACCRUAL_PER_EVENT,
                'eligible_gender' => LeaveType::GENDER_MALE,
            ],
            'UNPAID' => ['accrual_method' => LeaveType::ACCRUAL_UNLIMITED],
            'COMP-OFF' => ['accrual_method' => LeaveType::ACCRUAL_UNLIMITED],
        ];
    }
};
