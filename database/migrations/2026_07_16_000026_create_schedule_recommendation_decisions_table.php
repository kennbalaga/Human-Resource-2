<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_recommendation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_recommendation_id')->constrained(indexName: 'sched_rec_dec_rec_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30)->index();
            $table->foreignId('original_recommended_employee_id')->nullable()->constrained('employees', indexName: 'sched_rec_dec_original_emp_fk')->nullOnDelete();
            $table->foreignId('final_selected_employee_id')->nullable()->constrained('employees', indexName: 'sched_rec_dec_final_emp_fk')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->index(['schedule_recommendation_id', 'decided_at'], 'schedule_rec_decision_time_idx');
            $table->index(['user_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_recommendation_decisions');
    }
};
