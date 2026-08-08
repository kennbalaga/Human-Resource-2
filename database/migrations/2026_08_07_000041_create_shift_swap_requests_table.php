<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requester_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requester_assignment_id')->constrained('schedule_assignments')->restrictOnDelete();
            $table->foreignId('target_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('target_assignment_id')->constrained('schedule_assignments')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending_target')->index();
            $table->timestamp('target_responded_at')->nullable();
            $table->text('target_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['requester_employee_id', 'status']);
            $table->index(['target_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swap_requests');
    }
};
