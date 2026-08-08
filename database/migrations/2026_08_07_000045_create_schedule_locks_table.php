<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A locked period is a date range that write paths must check before touching
 * an assignment or day off — see ScheduleLockService::assertUnlocked(). Kept
 * as its own table rather than a new schedule_assignments.status value, since
 * every existing read query hardcodes where('status', 'scheduled').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('locked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('locked_at');
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'start_date', 'end_date', 'unlocked_at'], 'schedule_locks_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_locks');
    }
};
