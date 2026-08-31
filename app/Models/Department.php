<?php

namespace App\Models;

use App\Services\ReferenceDataCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_CLINICAL = 'clinical';

    public const CATEGORY_ADMINISTRATIVE = 'administrative';

    public const CATEGORY_SUPPORT = 'support';

    /**
     * The DOH general-ward standard of one nurse to twelve patients, used when a
     * bedded unit has not been given a ratio of its own.
     */
    public const DEFAULT_NURSE_PATIENT_RATIO = 12;

    protected $fillable = [
        'code',
        'name',
        'category',
        'bed_capacity',
        'nurse_patient_ratio',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'bed_capacity' => 'integer',
            'nurse_patient_ratio' => 'integer',
        ];
    }

    /**
     * Departments are served to most screens from ReferenceDataCache, so a write
     * has to drop that entry -- otherwise a renamed or retired unit keeps its old
     * name on screen until the TTL runs out.
     */
    protected static function booted(): void
    {
        $forget = static fn () => ReferenceDataCache::forget(static::class);

        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    /**
     * Nurses this unit must have on duty for any one shift, worked out from its
     * beds and ratio. Null when the unit has no beds and therefore no ratio-based
     * requirement to derive.
     */
    public function derivedMinimumStaffPerShift(): ?int
    {
        if ($this->bed_capacity === null || $this->bed_capacity < 1) {
            return null;
        }

        $ratio = $this->nurse_patient_ratio ?: self::DEFAULT_NURSE_PATIENT_RATIO;

        return (int) ceil($this->bed_capacity / max(1, $ratio));
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function shiftRequirements(): HasMany
    {
        return $this->hasMany(DepartmentShiftRequirement::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            self::CATEGORY_CLINICAL => 'Clinical Departments',
            self::CATEGORY_ADMINISTRATIVE => 'Administrative Departments',
            self::CATEGORY_SUPPORT => 'Support Services',
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::categories()[$this->category] ?? 'Unclassified departments';
    }
}
