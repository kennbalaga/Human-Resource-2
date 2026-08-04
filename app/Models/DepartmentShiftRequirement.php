<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The standing coverage a single shift of a single unit must be rostered to.
 */
class DepartmentShiftRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'shift_id',
        'minimum_staff',
        'minimum_senior',
    ];

    protected function casts(): array
    {
        return [
            'minimum_staff' => 'integer',
            'minimum_senior' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
