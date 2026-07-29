<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_CLINICAL = 'clinical';

    public const CATEGORY_ADMINISTRATIVE = 'administrative';

    public const CATEGORY_SUPPORT = 'support';

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            self::CATEGORY_CLINICAL => 'Clinical Departments',
            self::CATEGORY_ADMINISTRATIVE => 'Administrative Departments',
            self::CATEGORY_SUPPORT => 'Support Services',
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::categories()[$this->category] ?? 'Unclassified departments';
    }
}
