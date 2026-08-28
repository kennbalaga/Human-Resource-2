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
        // One settings row, found rather than assumed. The id was previously
        // pinned to 1, but the primary key is not fillable, so the row was
        // created with whatever the auto-increment counter held and a later
        // find(1) then missed it -- silently reverting to the .env default.
        $setting = EmployeeNumberSetting::query()->oldest('id')->firstOrNew();
        $setting->fill([
            'auto_generate' => $autoGenerate,
            'updated_by' => $user->id,
        ]);
        $setting->save();

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

        return $this->setting = EmployeeNumberSetting::query()->with('updater')->oldest('id')->first();
    }
}
