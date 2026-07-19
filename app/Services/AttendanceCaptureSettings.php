<?php

namespace App\Services;

use App\Models\AttendanceSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class AttendanceCaptureSettings
{
    public const BIOMETRIC_ONLY = 'biometric_only';

    public const HYBRID = 'hybrid';

    public const EMERGENCY_MANUAL = 'emergency_manual';

    /** @var list<string> */
    public const MODES = [self::BIOMETRIC_ONLY, self::HYBRID, self::EMERGENCY_MANUAL];

    private bool $loaded = false;

    private ?AttendanceSetting $setting = null;

    public function mode(): string
    {
        $setting = $this->current();
        $mode = $setting?->capture_mode ?? (string) config('workforce.attendance_capture_mode', self::HYBRID);

        if (! in_array($mode, self::MODES, true)) {
            return self::HYBRID;
        }

        if ($mode === self::EMERGENCY_MANUAL && $setting?->manual_mode_expires_at?->isPast()) {
            return self::BIOMETRIC_ONLY;
        }

        return $mode;
    }

    public function manualAllowed(): bool
    {
        return in_array($this->mode(), [self::HYBRID, self::EMERGENCY_MANUAL], true);
    }

    public function biometricAllowed(): bool
    {
        return in_array($this->mode(), [self::BIOMETRIC_ONLY, self::HYBRID], true);
    }

    public function manualReasonRequired(): bool
    {
        return $this->mode() === self::EMERGENCY_MANUAL;
    }

    public function reason(): ?string
    {
        return $this->current()?->manual_mode_reason;
    }

    public function expiresAt(): ?CarbonInterface
    {
        return $this->current()?->manual_mode_expires_at;
    }

    public function updatedBy(): ?User
    {
        return $this->current()?->updater;
    }

    public function update(
        User $user,
        string $mode,
        ?string $reason = null,
        ?CarbonInterface $expiresAt = null,
    ): AttendanceSetting {
        $setting = AttendanceSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'capture_mode' => $mode,
                'manual_mode_reason' => $mode === self::EMERGENCY_MANUAL ? $reason : null,
                'manual_mode_expires_at' => $mode === self::EMERGENCY_MANUAL ? $expiresAt : null,
                'updated_by' => $user->id,
            ],
        );

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        return $this->setting;
    }

    private function current(): ?AttendanceSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;
        if (! Schema::hasTable('attendance_settings')) {
            return null;
        }

        return $this->setting = AttendanceSetting::query()->with('updater')->find(1);
    }
}
