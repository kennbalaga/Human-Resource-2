<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'timezone',
        'theme',
        'email_notifications',
        'attendance_reminders',
        'schedule_updates',
        'leave_updates',
        'compact_navigation',
        'reduce_motion',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'attendance_reminders' => 'boolean',
            'schedule_updates' => 'boolean',
            'leave_updates' => 'boolean',
            'compact_navigation' => 'boolean',
            'reduce_motion' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
