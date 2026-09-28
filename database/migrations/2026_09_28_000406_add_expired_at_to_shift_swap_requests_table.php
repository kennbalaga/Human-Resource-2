<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A swap that was never answered has its own ending, and it is not the one
     * `cancelled_at` records: nobody withdrew it, the shift simply came and
     * went. Sharing that column would make the history read as though somebody
     * had thought about the request, which is the opposite of what happened.
     */
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn('expired_at');
        });
    }
};
