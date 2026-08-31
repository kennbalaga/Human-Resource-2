<?php

namespace App\Models;

use App\Services\ReferenceDataCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The highest rung on the seniority ladder a position may occupy. Mirrors the
     * Nurse I-V grades hospital rosters are built around.
     */
    public const MAX_SENIORITY_RANK = 5;

    /**
     * Plain-language names for each rung, so the roster screens read the way the
     * nursing office talks rather than showing a bare number.
     *
     * @var array<int, string>
     */
    public const SENIORITY_RANK_LABELS = [
        1 => 'Entry level / staff',
        2 => 'Experienced staff',
        3 => 'Senior / charge',
        4 => 'Supervisor / head',
        5 => 'Chief / director',
    ];

    protected $fillable = [
        'department_id',
        'code',
        'title',
        'seniority_rank',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'seniority_rank' => 'integer',
        ];
    }

    /**
     * Positions are served to most screens from ReferenceDataCache, so a write
     * has to drop that entry -- otherwise a retitled post keeps its old title on
     * screen until the TTL runs out.
     */
    protected static function booted(): void
    {
        $forget = static fn () => ReferenceDataCache::forget(static::class);

        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
