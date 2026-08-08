<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roster_drafts', function (Blueprint $table) {
            // The Step 3 rule set and Step 2 shift selection this draft was
            // built under, so resuming it re-evaluates against what was
            // actually on screen when it was saved — not whatever the form
            // happens to default to when the modal is reopened.
            $table->json('rules')->nullable()->after('entries');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roster_drafts', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
