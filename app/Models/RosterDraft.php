<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'department_id',
        'start_date',
        'end_date',
        'entries',
        'rules',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'published_at',
        'discarded_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'entries' => 'array',
            'rules' => 'array',
            'published_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
