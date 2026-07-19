<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'office_location_id',
        'code',
        'name',
        'provider',
        'serial_number',
        'is_active',
        'last_seen_at',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'configuration' => 'array',
        ];
    }

    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class);
    }

    public function scanEvents(): HasMany
    {
        return $this->hasMany(BiometricScanEvent::class);
    }
}
