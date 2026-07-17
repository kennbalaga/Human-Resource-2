<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missingSecret = ! Schema::hasColumn('users', 'two_factor_secret');
        $missingRecoveryCodes = ! Schema::hasColumn('users', 'two_factor_recovery_codes');
        $missingConfirmedAt = ! Schema::hasColumn('users', 'two_factor_confirmed_at');

        if (! $missingSecret && ! $missingRecoveryCodes && ! $missingConfirmedAt) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($missingSecret, $missingRecoveryCodes, $missingConfirmedAt): void {
            if ($missingSecret) {
                $table->text('two_factor_secret')->nullable();
            }

            if ($missingRecoveryCodes) {
                $table->text('two_factor_recovery_codes')->nullable();
            }

            if ($missingConfirmedAt) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // This repair migration does not remove security data on rollback.
    }
};
