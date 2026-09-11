<?php

namespace App\Models;

use App\Services\ReferenceDataCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    /** Required by law. The entitlement is not the company's to set or withdraw. */
    public const CATEGORY_STATUTORY = 'statutory';

    /** Offered by company policy. Whatever the handbook says it is. */
    public const CATEGORY_COMPANY = 'company';

    /** A yearly allowance held as credits, drawn down request by request. */
    public const ACCRUAL_ANNUAL = 'annual';

    /**
     * Granted on the occasion rather than by the year: a childbirth, a
     * surgery, an incident. The entitlement is the ceiling for one occasion,
     * so it caps a single request and is never accumulated into a balance --
     * the reason maternity leave no longer resets to 105 days every January.
     */
    public const ACCRUAL_PER_EVENT = 'per_event';

    /** No entitlement at all; granted as circumstances call for it. Unpaid leave, comp-off. */
    public const ACCRUAL_UNLIMITED = 'unlimited';

    /** Weekends do not count against the entitlement. */
    public const BASIS_WORKING = 'working';

    /** Every day in the range counts, weekends included, as maternity leave is reckoned. */
    public const BASIS_CALENDAR = 'calendar';

    /**
     * A standing status HR has verified on the employee record, which a type
     * may demand before it can be claimed at all. Solo parent leave is the
     * only one so far -- the seven days belong to holders of a DSWD solo
     * parent ID, not to the whole workforce.
     */
    public const DESIGNATION_SOLO_PARENT = 'solo_parent';

    public const GENDER_FEMALE = 'female';

    public const GENDER_MALE = 'male';

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'color',
        'annual_entitlement',
        'accrual_method',
        'day_basis',
        'max_carry_over',
        'requires_attachment',
        'eligible_gender',
        'min_service_months',
        'requires_designation',
        'satisfies_sil',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_entitlement' => 'decimal:2',
            'max_carry_over' => 'decimal:2',
            'requires_attachment' => 'boolean',
            'min_service_months' => 'integer',
            'satisfies_sil' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Leave types are served to most screens from ReferenceDataCache, so a write
     * has to drop that entry -- otherwise a renamed type keeps its old name on
     * screen until the TTL runs out.
     */
    protected static function booted(): void
    {
        $forget = static fn () => ReferenceDataCache::forget(static::class);

        static::saved($forget);
        static::deleted($forget);
    }

    /** @return array<int, string> */
    public static function categories(): array
    {
        return [self::CATEGORY_STATUTORY, self::CATEGORY_COMPANY];
    }

    /** @return array<int, string> */
    public static function accrualMethods(): array
    {
        return [self::ACCRUAL_ANNUAL, self::ACCRUAL_PER_EVENT, self::ACCRUAL_UNLIMITED];
    }

    /** @return array<int, string> */
    public static function dayBases(): array
    {
        return [self::BASIS_WORKING, self::BASIS_CALENDAR];
    }

    /** @return array<int, string> */
    public static function genders(): array
    {
        return [self::GENDER_FEMALE, self::GENDER_MALE];
    }

    /** @return array<int, string> */
    public static function designations(): array
    {
        return [self::DESIGNATION_SOLO_PARENT];
    }

    public function isStatutory(): bool
    {
        return $this->category === self::CATEGORY_STATUTORY;
    }

    /**
     * Whether this type draws on a yearly credit balance. Only annual types do.
     * The rest are governed by their per-request ceiling and by approval, and
     * are kept out of any figure that adds credits up -- a placeholder
     * entitlement counted as though it were held is what once had the balance
     * tile reading 890 days.
     */
    public function isBalanceBacked(): bool
    {
        return $this->accrualMethod() === self::ACCRUAL_ANNUAL;
    }

    /**
     * A row with no accrual method is one that predates the classification
     * columns, and a yearly allowance is exactly what every type was before
     * them. Reading the absent value as anything else makes an unmigrated
     * database dangerous rather than merely stale: balancesFor would open the
     * year with zero credits for every employee and every type.
     */
    private function accrualMethod(): string
    {
        return $this->accrual_method ?? self::ACCRUAL_ANNUAL;
    }

    public function isPerEvent(): bool
    {
        return $this->accrualMethod() === self::ACCRUAL_PER_EVENT;
    }

    public function usesCalendarDays(): bool
    {
        return $this->day_basis === self::BASIS_CALENDAR;
    }

    /**
     * The most days one request may cover, or null where nothing but the
     * global cap applies. Annual types return null because the balance, not a
     * ceiling, is what limits them.
     */
    public function maximumDaysPerRequest(): ?float
    {
        return $this->isPerEvent() ? (float) $this->annual_entitlement : null;
    }

    /** How the entitlement reads on screen where a number would mislead. */
    public function entitlementSummary(): string
    {
        return match ($this->accrualMethod()) {
            self::ACCRUAL_PER_EVENT => 'Up to '.number_format((float) $this->annual_entitlement, 0).' days per occurrence',
            self::ACCRUAL_UNLIMITED => 'No fixed cap',
            default => number_format((float) $this->annual_entitlement, 1).' days per year',
        };
    }

    /** @param  Builder<LeaveType>  $query */
    public function scopeStatutory(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_STATUTORY);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
