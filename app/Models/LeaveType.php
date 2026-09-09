<?php

namespace App\Models;

use App\Services\ReferenceDataCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    /**
     * At or above this, an entitlement is a placeholder rather than a credit.
     *
     * Unpaid leave and comp-off are granted as circumstances call for them, and
     * are seeded with 366 -- every day of a leap year -- to mean "no fixed
     * annual cap". Reading that back as though somebody held 366 days of credit
     * is what made the leave page advertise a balance of 890 days.
     */
    public const UNCAPPED_ENTITLEMENT = 365;

    protected $fillable = [
        'code',
        'name',
        'description',
        'color',
        'annual_entitlement',
        'max_carry_over',
        'requires_attachment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_entitlement' => 'decimal:2',
            'max_carry_over' => 'decimal:2',
            'requires_attachment' => 'boolean',
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

    /** Whether this type is granted on the circumstances rather than from a yearly allowance. */
    public function isUncapped(): bool
    {
        return (float) $this->annual_entitlement >= self::UNCAPPED_ENTITLEMENT;
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
