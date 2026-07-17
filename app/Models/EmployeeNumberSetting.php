<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeNumberSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_generate',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'auto_generate' => 'boolean',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
