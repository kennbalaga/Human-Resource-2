<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'color',
        'annual_entitlement',
        'max_carry_over',
        'requires_attachment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_entitlement' => 'decimal:2',
            'max_carry_over' => 'decimal:2',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
