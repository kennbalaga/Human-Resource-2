<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the one session an account is currently allowed to hold.
 *
 * Signing in mints a token, keeps it in that browser's session, and writes it
 * here; every later request compares the two, so a second sign-in anywhere
 * else displaces the first device rather than running beside it.
 *
 * A token rather than the session id, because the session id is rewritten
 * whenever Laravel regenerates it — at sign-in, and on any future defence
 * against session fixation — while the token rides along inside the session
 * data and survives.
 *
 * Left null for accounts that have not signed in since this column arrived,
 * which is what keeps a deploy from turning every open session into a forced
 * sign-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('active_session_token', 64)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('active_session_token');
        });
    }
};
