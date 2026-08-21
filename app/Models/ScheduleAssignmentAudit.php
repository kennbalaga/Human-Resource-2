<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only by convention: nothing in this app is meant to update or delete
 * a row here, enforced below at the code level. That's weaker than a real
 * database grant (Layer 4, deliberately out of scope) — it stops accidental
 * mutation from application code, not a determined actor with a DB client.
 */
class ScheduleAssignmentAudit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'schedule_assignment_id',
        'employee_id',
        'work_date',
        'action',
        'actor_id',
        'created_via',
        'unattended',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'unattended' => 'boolean',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('schedule_assignment_audits is append-only.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('schedule_assignment_audits is append-only.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
