<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'requested_by',
        'target_shift_id',
        'target_work_date',
        'target_department_id',
        'target_position_id',
        'recommended_employee_id',
        'alternative_candidates',
        'eligibility_results',
        'warnings',
        'workload_risk',
        'explanation',
        'explanation_source',
        'fingerprint',
        'status',
        'actor_type',
        'actor_name',
        'action_type',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'target_work_date' => 'date',
            'alternative_candidates' => 'array',
            'eligibility_results' => 'array',
            'warnings' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'target_shift_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'target_department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'target_position_id');
    }

    public function recommendedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recommended_employee_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ScheduleRecommendationDecision::class);
    }
}
