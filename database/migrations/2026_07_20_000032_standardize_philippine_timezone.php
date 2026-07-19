<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_preferences')) {
            DB::table('user_preferences')->update(['timezone' => 'Asia/Manila']);
        }

        if (Schema::hasTable('office_locations')) {
            DB::table('office_locations')->update(['timezone' => 'Asia/Manila']);
        }

        if (Schema::hasTable('attendance_settings') && Schema::hasColumn('attendance_settings', 'manual_mode_expires_at')) {
            DB::table('attendance_settings')
                ->whereNotNull('manual_mode_expires_at')
                ->orderBy('id')
                ->eachById(function (object $setting): void {
                    DB::table('attendance_settings')->where('id', $setting->id)->update([
                        'manual_mode_expires_at' => Carbon::parse($setting->manual_mode_expires_at, 'UTC')
                            ->subHours(8)
                            ->format('Y-m-d H:i:s'),
                    ]);
                });
        }
    }

    public function down(): void
    {
        // Timezone choices and ambiguous legacy expiry values cannot be safely reconstructed.
    }
};
