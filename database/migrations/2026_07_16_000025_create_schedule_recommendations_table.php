<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_recommendations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_shift_id')->constrained('shifts')->restrictOnDelete();
            $table->date('target_work_date');
            $table->foreignId('target_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('target_position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignId('recommended_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->json('alternative_candidates')->nullable();
            $table->json('eligibility_results');
            $table->json('warnings')->nullable();
            $table->string('workload_risk', 20)->nullable();
            $table->text('explanation')->nullable();
            $table->string('explanation_source', 20)->default('laravel');
            $table->string('fingerprint', 64);
            $table->string('status', 30)->default('for_hr_review')->index();
            $table->string('actor_type', 30)->default('AI_SYSTEM');
            $table->string('actor_name')->default('AI Scheduling Assistant');
            $table->string('action_type', 50)->default('GENERATED_RECOMMENDATION');
            $table->dateTime('generated_at');
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->index(['target_work_date', 'target_department_id'], 'schedule_rec_target_date_dept_idx');
            $table->index(['requested_by', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_recommendations');
    }
};
