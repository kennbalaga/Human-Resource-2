<?php

namespace App\Services\Organization;

use App\Models\NotificationEmailSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The system-wide switch for notification email.
 *
 * This used to be four checkboxes on every employee's own settings page, which
 * made being reachable a personal preference: an employee could turn off
 * schedule emails and then hear about a shift change only by opening the app,
 * which is exactly the case notification email exists for. It is now one
 * decision a System Administrator makes for everybody, and the default is on.
 *
 * Read once per notification sent, so cached the way the two-factor
 * enforcement flag is, and cleared on update so a change in the settings
 * screen takes effect immediately.
 */
class NotificationEmailSettings
{
    private const CACHE_KEY = 'notifications.email_enabled';

    private const TABLE_CACHE_KEY = 'notifications.email_settings_table_exists';

    // Kept short: the cache is per machine, so this is how long an
    // administrator's change takes to reach the other processes.
    private const CACHE_TTL_SECONDS = 60;

    private bool $loaded = false;

    private ?NotificationEmailSetting $setting = null;

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

    /**
     * Null when the table is not there yet.
     *
     * enabled() already answers for an installation whose migrations have not
     * run, by falling back to sending — so without the same guard here, the
     * settings page would show a switch it could not store and a save would be
     * a 500. The caller says so instead.
     */
    public function update(User $user, bool $enabled): ?NotificationEmailSetting
    {
        if (! $this->tableExists()) {
            return null;
        }

        // One settings row, found rather than assumed: the primary key is not
        // fillable, so pinning an id here would miss a row created with
        // whatever the auto-increment counter held.
        $setting = NotificationEmailSetting::query()->oldest('id')->firstOrNew();
        $setting->fill([
            'enabled' => $enabled,
            'updated_by' => $user->id,
        ]);
        $setting->save();

        $this->setting = $setting->load('updater');
        $this->loaded = true;

        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TABLE_CACHE_KEY);

        return $this->setting;
    }

    private function current(): ?NotificationEmailSetting
    {
        if ($this->loaded) {
            return $this->setting;
        }

        $this->loaded = true;

        if (! $this->tableExists()) {
            return null;
        }

        return $this->setting = NotificationEmailSetting::query()->oldest('id')->first();
    }

    /**
     * Guards an installation whose migrations have not run yet. The answer only
     * changes on migrate, so it is cached rather than costing an
     * information_schema round trip per notification.
     */
    private function tableExists(): bool
    {
        return Cache::remember(
            self::TABLE_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): bool => Schema::hasTable('notification_email_settings'),
        );
    }
}
