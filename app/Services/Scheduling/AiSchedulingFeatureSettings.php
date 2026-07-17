<?php

namespace App\Services\Scheduling;

use App\Models\AiSchedulingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AiSchedulingFeatureSettings
{
    private bool $loaded = false;

    private ?AiSchedulingSetting $setting = null;

    public function assistantEnabled(): bool
    {
        return $this->current()?->assistant_enabled
            ?? (bool) config('ai_workforce_scheduling.enabled');
    }

    public function geminiExplanationsEnabled(): bool
    {
        if (! $this->assistantEnabled()) {
            return false;
        }

        return $this->current()?->gemini_explanations_enabled
            ?? (bool) config('ai_workforce_scheduling.gemini_explanations_enabled');
    }

    public function source(): string
    {
        return $this->current() ? 'admin_setting' : 'environment';
    }

    public function updatedBy(): ?User
    {
        return $this->current()?->updater;
    }

    public function update(User $user, bool $assistantEnabled, bool $geminiExplanationsEnabled): AiSchedulingSetting
    {
        $setting = AiSchedulingSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'assistant_enabled' => $assistantEnabled,
                'gemini_explanations_enabled' => $assistantEnabled && $geminiExplanationsEnabled,
                'updated_by' => $user->id,
            ],
        );

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        return $this->setting;
    }

    private function current(): ?AiSchedulingSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;
        if (! Schema::hasTable('ai_scheduling_settings')) {
            return null;
        }

        return $this->setting = AiSchedulingSetting::query()->with('updater')->find(1);
    }
}
