<?php

namespace App\Services\Organization;

use App\Models\TwoFactorEnforcementSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class TwoFactorEnforcementSettings
{
    private bool $loaded = false;

    private ?TwoFactorEnforcementSetting $setting = null;

    public function enabled(): bool
    {
        return $this->current()?->enabled ?? true;
    }

    public function updatedBy(): ?User
    {
        return $this->current()?->updater;
    }

    public function update(User $user, bool $enabled): TwoFactorEnforcementSetting
    {
        $setting = TwoFactorEnforcementSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'enabled' => $enabled,
                'updated_by' => $user->id,
            ],
        );

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        return $this->setting;
    }

    private function current(): ?TwoFactorEnforcementSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;
        if (! Schema::hasTable('two_factor_enforcement_settings')) {
            return null;
        }

        return $this->setting = TwoFactorEnforcementSetting::query()->with('updater')->find(1);
    }
}
