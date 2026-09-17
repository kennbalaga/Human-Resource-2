<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One burnout risk assessment per employee per day.
 *
 * Kept as a table rather than computed on every page load for two reasons.
 * The trend is the point of the indicator, and a trend needs yesterday's
 * answer. And the hospital-wide list would otherwise re-read four weeks of
 * attendance for every employee on every visit, against a database whose
 * cost is round trips rather than rows.
 *
 * Deleted with the employee: an assessment is derived from their records and
 * means nothing without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('burnout_risk_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('score', 5, 2);
            $table->decimal('previous_score', 5, 2)->nullable();
            $table->string('level', 10);
            $table->json('factors');
            $table->timestamps();

            $table->unique(['employee_id', 'as_of_date']);
            $table->index(['as_of_date', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('burnout_risk_snapshots');
    }
};
