<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    public const TYPE_REGULAR = 'regular';

    public const TYPE_SPECIAL = 'special';

    protected $fillable = [
        'date',
        'name',
        'type',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function typeLabel(): string
    {
        return $this->type === self::TYPE_SPECIAL ? 'Special non-working day' : 'Regular holiday';
    }
}
