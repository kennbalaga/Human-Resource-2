<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_assignment_audits', function (Blueprint $table) {
            $table->id();
            // Deliberately not a foreign key: a 'deleted' audit row must
            // survive the assignment it describes being deleted, and a
            // nullOnDelete() constraint would erase exactly that.
            $table->unsignedBigInteger('schedule_assignment_id')->index();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->date('work_date')->nullable();
            $table->string('action', 10);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_via', 20)->nullable();
            $table->boolean('unattended')->default(false);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['schedule_assignment_id', 'action']);
            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_assignment_audits');
    }
};
