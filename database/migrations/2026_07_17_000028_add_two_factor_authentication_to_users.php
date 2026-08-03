<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->timestamp('two_factor_enabled_at')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_locked_until')->nullable()->after('two_factor_enabled_at');
            $table->unsignedTinyInteger('two_factor_failed_attempts')->default(0)->after('two_factor_locked_until');
        });

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
