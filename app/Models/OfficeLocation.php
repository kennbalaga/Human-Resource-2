<?php

namespace App\Models;

use App\Services\ReferenceDataCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'timezone',
        'work_start_time',
        'work_end_time',
        'grace_period_minutes',
        'break_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The office is read on every attendance screen and served from
     * ReferenceDataCache, so a write has to drop that entry -- otherwise an
     * edited work window keeps its old hours until the TTL runs out.
     */
    protected static function booted(): void
    {
        $forget = static fn () => ReferenceDataCache::forget(static::class);

        static::saved($forget);
        static::deleted($forget);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
