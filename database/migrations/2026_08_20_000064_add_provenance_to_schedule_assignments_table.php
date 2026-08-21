<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->string('created_via', 20)->default('legacy')->after('created_by');
            $table->foreignId('source_recommendation_id')->nullable()->after('created_via')
                ->constrained('schedule_recommendations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_recommendation_id');
            $table->dropColumn('created_via');
        });
    }
};
