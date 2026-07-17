<?php

namespace App\Services\Organization;

use App\Models\EmployeeNumberSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class EmployeeNumberSettings
{
    private bool $loaded = false;

    private ?EmployeeNumberSetting $setting = null;

    public function autoGenerateEnabled(): bool
    {
        return $this->current()?->auto_generate
            ?? (bool) config('workforce.employee_number_auto_generate', true);
    }

    public function source(): string
    {
        return $this->current() ? 'admin_setting' : 'environment';
    }

    public function updatedBy(): ?User
    {
        return $this->current()?->updater;
    }

    public function update(User $user, bool $autoGenerate): EmployeeNumberSetting
    {
        $setting = EmployeeNumberSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'auto_generate' => $autoGenerate,
                'updated_by' => $user->id,
            ],
        );

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        return $this->setting;
    }

    private function current(): ?EmployeeNumberSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;
        if (! Schema::hasTable('employee_number_settings')) {
            return null;
        }

        return $this->setting = EmployeeNumberSetting::query()->with('updater')->find(1);
    }
}
