<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A request can stop being answerable for more than one reason — the shift
     * was worked, or somebody in it no longer swaps shifts at all — and the two
     * are not the same news to the people who filed it. Closing both as a bare
     * "expired" would replace a request that sat there saying nothing with one
     * that ends saying nothing, which is half a fix.
     */
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->string('expired_reason')->nullable()->after('expired_at');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn('expired_reason');
        });
    }
};
