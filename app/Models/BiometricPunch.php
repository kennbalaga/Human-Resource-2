<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan as the terminal reported it.
 *
 * This is the immutable audit trail the ethics board cares about: raw punches
 * are what the device said, AttendanceRecord is what the system derived from
 * them and HR then approved. The two are deliberately separate, so a corrected
 * or rejected attendance record never rewrites the underlying evidence.
 *
 * No fingerprint or face template is stored here, or anywhere else in this
 * database -- templates stay on the terminal (RA 10173). What is kept is the
 * PIN, the instant, the verification mode and the device serial.
 */
class BiometricPunch extends Model
{
    use HasFactory;

    protected $fillable = [
        'fingerprint',
        'device_sn',
        'pin',
        'employee_id',
        'punched_at',
        'punch_code',
        'verify_mode',
        'received_at',
        'processed_at',
        'processing_status',
        'failure_reason',
        'biometric_scan_event_id',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'punch_code' => 'integer',
            'verify_mode' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scanEvent(): BelongsTo
    {
        return $this->belongsTo(BiometricScanEvent::class, 'biometric_scan_event_id');
    }
}
