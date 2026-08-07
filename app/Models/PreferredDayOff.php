<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreferredDayOff extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'employee_id',
        'preferred_date',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
