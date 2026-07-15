<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'provider',
        'event_type',
        'status',
        'response_code',
        'duration_ms',
        'endpoint_host',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
