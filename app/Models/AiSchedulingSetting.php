<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSchedulingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'assistant_enabled',
        'gemini_explanations_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'assistant_enabled' => 'boolean',
            'gemini_explanations_enabled' => 'boolean',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
