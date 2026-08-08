<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'requester_employee_id',
        'requester_assignment_id',
        'target_employee_id',
        'target_assignment_id',
        'reason',
        'status',
        'target_responded_at',
        'target_notes',
        'reviewed_by',
        'reviewed_at',
        'reviewer_notes',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'target_responded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function requesterEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function targetEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'target_employee_id');
    }

    public function requesterAssignment(): BelongsTo
    {
        return $this->belongsTo(ScheduleAssignment::class, 'requester_assignment_id');
    }

    public function targetAssignment(): BelongsTo
    {
        return $this->belongsTo(ScheduleAssignment::class, 'target_assignment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
