<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScheduleSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'early_window_minutes',
        'grace_minutes',
        'late_bind_minutes',
        'schedule_aware',
        'enforce_published_shift',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'schedule_aware' => 'boolean',
            'enforce_published_shift' => 'boolean',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
