<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Written to be safe to re-run. A database restored from a dump can arrive with
 * some of these columns already present but no migration record, and a plain
 * `add column` would then abort the whole migration run.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'two_factor_enabled_at')) {
                $table->timestamp('two_factor_enabled_at')->nullable()->after('two_factor_secret');
            }

            if (! Schema::hasColumn('users', 'two_factor_locked_until')) {
                $table->timestamp('two_factor_locked_until')->nullable()->after('two_factor_enabled_at');
            }

            if (! Schema::hasColumn('users', 'two_factor_failed_attempts')) {
                $table->unsignedTinyInteger('two_factor_failed_attempts')->default(0)->after('two_factor_locked_until');
            }
        });

        if (Schema::hasTable('two_factor_recovery_codes')) {
            return;
        }

        Schema::create('two_factor_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_recovery_codes');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_enabled_at', 'two_factor_locked_until', 'two_factor_failed_attempts']);
        });
    }
};
