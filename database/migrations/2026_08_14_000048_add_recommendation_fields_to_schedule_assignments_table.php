<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->foreignId('schedule_recommendation_id')->nullable()->after('recurring_schedule_id')->constrained('schedule_recommendations')->nullOnDelete();
            $table->enum('source', ['ai', 'manual'])->default('manual')->after('status');
            $table->foreignId('applied_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->boolean('was_modified')->default(false)->after('applied_by');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_recommendation_id');
            $table->dropConstrainedForeignId('applied_by');
            $table->dropColumn(['source', 'was_modified']);
        });
    }
};
