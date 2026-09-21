<?php

namespace App\Models;

use App\Services\ReferenceDataCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A theatre, ward, clinic or delivery room — a place inside a unit that a shift
 * can be worked in.
 */
class Room extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_CLOSED = 'closed';

    /**
     * The default lowest seniority rank that counts as charge cover in a theatre
     * or a delivery room. Anything below it is entry level, and a theatre left
     * to entry level alone is the case this exists to refuse.
     */
    public const THEATRE_MINIMUM_RANK = 3;

    protected $fillable = [
        'department_id',
        'code',
        'name',
        'room_type',
        'bed_capacity',
        'max_staff',
        'min_seniority_rank',
        'status',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bed_capacity' => 'integer',
            'max_staff' => 'integer',
            'min_seniority_rank' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Rooms are read by the board and the day roster on every request and change
     * perhaps twice a year, so they are served from the reference cache like the
     * other small lookup tables. A write drops the entry.
     */
    protected static function booted(): void
    {
        $forget = static fn () => ReferenceDataCache::forget(static::class);

        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            'operating' => 'Operating room',
            'delivery' => 'Delivery room',
            'ward' => 'Ward',
            'clinic' => 'Clinic room',
            'procedure' => 'Procedure room',
            'isolation' => 'Isolation room',
            'recovery' => 'Recovery room',
            'treatment' => 'Treatment room',
            'imaging' => 'Imaging room',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_MAINTENANCE => 'Under maintenance',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    /**
     * Rooms where a theatre-grade skill mix is the expectation rather than the
     * exception, used to seed a sensible default rank on a new room.
     *
     * @return array<int, string>
     */
    public static function restrictedTypes(): array
    {
        return ['operating', 'delivery'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shiftRequirements(): HasMany
    {
        return $this->hasMany(RoomShiftRequirement::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether this room can take an assignment at all. A room under maintenance
     * or closed cannot, whatever the roster says.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Nurses this room needs on any one shift, worked out from its own beds and
     * its unit's nurse-to-patient ratio. Null when the room has no beds, which
     * is every theatre and every clinic room — there is nothing to derive from a
     * room that does not admit patients.
     */
    public function derivedMinimumStaffPerShift(): ?int
    {
        if ($this->bed_capacity === null || $this->bed_capacity < 1) {
            return null;
        }

        $ratio = $this->department?->nurse_patient_ratio
            ?: Department::DEFAULT_NURSE_PATIENT_RATIO;

        return (int) ceil($this->bed_capacity / max(1, $ratio));
    }

    public function getTypeLabelAttribute(): string
    {
        return self::types()[$this->room_type] ?? 'Room';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statuses()[$this->status] ?? 'Unknown';
    }
}
