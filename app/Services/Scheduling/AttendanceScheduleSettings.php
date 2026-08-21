<?php

namespace App\Services\Scheduling;

use App\Models\AttendanceScheduleSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AttendanceScheduleSettings
{
    private bool $loaded = false;

    private ?AttendanceScheduleSetting $setting = null;

    public function earlyWindowMinutes(): int
    {
        return $this->current()?->early_window_minutes ?? 60;
    }

    public function graceMinutes(): int
    {
        return $this->current()?->grace_minutes ?? 15;
    }

    public function lateBindMinutes(): int
    {
        return $this->current()?->late_bind_minutes ?? 240;
    }

    public function scheduleAware(): bool
    {
        return $this->current()?->schedule_aware ?? false;
    }

    public function enforcePublishedShift(): bool
    {
        return $this->current()?->enforce_published_shift ?? false;
    }

    public function updatedBy(): ?User
    {
        return $this->current()?->updater;
    }

    /**
     * @param  array{early_window_minutes: int, grace_minutes: int, late_bind_minutes: int, schedule_aware: bool, enforce_published_shift: bool}  $data
     */
    public function update(User $user, array $data): AttendanceScheduleSetting
    {
        $setting = AttendanceScheduleSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'early_window_minutes' => $data['early_window_minutes'],
                'grace_minutes' => $data['grace_minutes'],
                'late_bind_minutes' => $data['late_bind_minutes'],
                'schedule_aware' => $data['schedule_aware'],
                'enforce_published_shift' => $data['enforce_published_shift'],
                'updated_by' => $user->id,
            ],
        );

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        return $this->setting;
    }

    private function current(): ?AttendanceScheduleSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;
        if (! Schema::hasTable('attendance_schedule_settings')) {
            return null;
        }

        return $this->setting = AttendanceScheduleSetting::query()->with('updater')->find(1);
    }
}
