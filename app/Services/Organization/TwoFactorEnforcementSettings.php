<?php

namespace App\Services\Organization;

use App\Models\TwoFactorEnforcementSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class TwoFactorEnforcementSettings
{
    /**
     * The enforcement flag is read by middleware on every authenticated request,
     * so it is cached rather than re-queried. `update()` clears the cache, which
     * keeps a toggle in the settings screen effective immediately.
     */
    private const CACHE_KEY = 'security.two_factor.enforcement_enabled';

    private const TABLE_CACHE_KEY = 'security.two_factor.enforcement_table_exists';

    // Kept short: the cache is per machine, so this is how long another user's
    // toggle takes to reach everyone else.
    private const CACHE_TTL_SECONDS = 60;

    private bool $loaded = false;

    private ?TwoFactorEnforcementSetting $setting = null;

    public function enabled(): bool
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): bool => $this->current()?->enabled ?? true,
        );
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

        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TABLE_CACHE_KEY);

        return $this->setting;
    }

    private function current(): ?TwoFactorEnforcementSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;

        // Guards installations whose migrations have not run yet. The answer only
        // changes on migrate, so it is cached to avoid an information_schema
        // round trip on every request.
        $tableExists = Cache::remember(
            self::TABLE_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): bool => Schema::hasTable('two_factor_enforcement_settings'),
        );

        if (! $tableExists) {
            return null;
        }

        return $this->setting = TwoFactorEnforcementSetting::query()->find(1);
    }
}
