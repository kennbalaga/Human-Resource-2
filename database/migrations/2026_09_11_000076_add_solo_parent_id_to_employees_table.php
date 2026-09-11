<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A solo parent ID is issued by the DSWD and carries an expiry, so the record
 * has to hold the date rather than a boolean: an employee whose ID lapsed is
 * not a solo parent for the purpose of claiming the leave, and a tick box
 * nobody revisits would keep granting it forever.
 *
 * An employee with no expiry date holds no designation, which is what every
 * record reads as until HR fills one in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('solo_parent_id_number', 50)->nullable()->after('gender');
            $table->date('solo_parent_id_expires_on')->nullable()->after('solo_parent_id_number');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['solo_parent_id_number', 'solo_parent_id_expires_on']);
        });
    }
};
