<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The standing cover one shift of one room must be staffed to, and whether the
 * room runs that shift at all.
 */
class RoomShiftRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'shift_id',
        'operates',
        'minimum_staff',
        'minimum_senior',
    ];

    protected function casts(): array
    {
        return [
            'operates' => 'boolean',
            'minimum_staff' => 'integer',
            'minimum_senior' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
