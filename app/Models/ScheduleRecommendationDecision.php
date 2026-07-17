<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRecommendationDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_recommendation_id',
        'user_id',
        'action',
        'original_recommended_employee_id',
        'final_selected_employee_id',
        'reason',
        'metadata',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(ScheduleRecommendation::class, 'schedule_recommendation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
