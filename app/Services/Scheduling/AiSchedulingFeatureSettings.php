<?php

namespace App\Services\Scheduling;

use App\Models\AiSchedulingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AiSchedulingFeatureSettings
{
    /**
     * The schedule screens read these flags on every render, so the stored values
     * are cached instead of re-queried. `update()` clears the cache, which keeps
     * an administrator's toggle effective immediately.
     */
    private const CACHE_KEY = 'scheduling.ai_feature_flags';

    private const TABLE_CACHE_KEY = 'scheduling.ai_settings_table_exists';

    // Kept short: the cache is per machine, so this is how long another user's
    // toggle takes to reach everyone else.
    private const CACHE_TTL_SECONDS = 60;

    private bool $loaded = false;

    private ?AiSchedulingSetting $setting = null;

    public function assistantEnabled(): bool
    {
        return $this->flags()['assistant_enabled']
            ?? (bool) config('ai_workforce_scheduling.enabled');
    }

    public function geminiExplanationsEnabled(): bool
    {
        if (! $this->assistantEnabled()) {
            return false;
        }

        return $this->flags()['gemini_explanations_enabled']
            ?? (bool) config('ai_workforce_scheduling.gemini_explanations_enabled');
    }

    public function source(): string
    {
        return $this->flags()['stored'] ? 'admin_setting' : 'environment';
    }

    public function updatedBy(): ?User
    {
        return $this->current()?->updater;
    }

    public function update(User $user, bool $assistantEnabled, bool $geminiExplanationsEnabled): AiSchedulingSetting
    {
        // One settings row, found rather than assumed. The id was previously
        // pinned to 1, but the primary key is not fillable, so the row was
        // created with whatever the auto-increment counter held and a later
        // find(1) then missed it -- silently reverting to the .env default.
        $setting = AiSchedulingSetting::query()->oldest('id')->firstOrNew();
        $setting->fill([
            'assistant_enabled' => $assistantEnabled,
            'gemini_explanations_enabled' => $assistantEnabled && $geminiExplanationsEnabled,
            'updated_by' => $user->id,
        ]);
        $setting->save();

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TABLE_CACHE_KEY);

        return $this->setting;
    }

    /**
     * @return array{stored: bool, assistant_enabled: bool|null, gemini_explanations_enabled: bool|null}
     */
    private function flags(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $setting = $this->current();

            return [
                'stored' => $setting !== null,
                'assistant_enabled' => $setting?->assistant_enabled,
                'gemini_explanations_enabled' => $setting?->gemini_explanations_enabled,
            ];
        });
    }

    private function current(): ?AiSchedulingSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;

        // Guards installations whose migrations have not run yet. The answer only
        // changes on migrate, so it is cached to avoid an information_schema
        // round trip every time the schedule screens are opened.
        $tableExists = Cache::remember(
            self::TABLE_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): bool => Schema::hasTable('ai_scheduling_settings'),
        );

        if (! $tableExists) {
            return null;
        }

        return $this->setting = AiSchedulingSetting::query()->oldest('id')->first();
    }
}
