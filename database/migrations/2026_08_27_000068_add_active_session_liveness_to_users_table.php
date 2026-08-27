<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the account's session slot a way to be let go of.
 *
 * The token alone says which session holds the account, never whether that
 * session is still there. Sessions end without saying so — a browser closed,
 * a laptop shut, thirty idle minutes — and the token outlived every one of
 * them, so the account stayed marked as open on a device that had long since
 * stopped existing and the next honest sign-in was turned away for it.
 *
 * Two things are recorded alongside the token so the slot can be told apart
 * from an abandoned one:
 *
 * - when the device holding it was last heard from, which is how a claim is
 *   known to have outlived the session that made it;
 * - which browser is holding it, so the same person returning to the same
 *   browser is recognised as coming back rather than arriving second.
 *
 * The browser is recorded by hash: knowing the value in the cookie is what
 * makes a device that device, and the database has no use for it in the clear.
 *
 * Accounts already holding a claim when this arrives were never heard from in
 * these terms, so they are credited with the last time anything is known to
 * have been true of them: the sign-in that made the claim. A device still
 * working says so again within the second, and one that stopped working
 * yesterday stops standing in the way of the person trying to get back in —
 * which is the whole of what this is here to end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('active_session_last_seen_at')->nullable()->after('active_session_token');
            $table->char('active_session_device_hash', 64)->nullable()->after('active_session_last_seen_at');
        });

        DB::table('users')
            ->whereNotNull('active_session_token')
            ->update(['active_session_last_seen_at' => DB::raw('last_login_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['active_session_last_seen_at', 'active_session_device_hash']);
        });
    }
};
