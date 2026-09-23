<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones allowed to sign in without an authenticator code.
 *
 * ---- What is stored here, and what deliberately is not ----
 *
 * The app lock this trust is earned by is entirely device-local: a PBKDF2 digest
 * of a six-digit PIN and, optionally, a WebAuthn credential id, both in that
 * phone's own localStorage and neither of them ever transmitted. None of that
 * appears below and none of it may ever appear below — the hospital does not
 * become the custodian of a biometric or of a PIN, which is the whole design of
 * that feature and is asserted by tests/Feature/Pwa/DeviceLockTest.php.
 *
 * What is stored is a bearer token that means only "the app lock on this handset
 * was set up by this account". It says nothing about the PIN, cannot be used to
 * guess it, and is worth nothing on a different device: the row is keyed on the
 * hash of the year-long hrms_device cookie as well, so a token lifted from
 * one phone's storage and replayed from another matches no row.
 *
 * ---- Why a token AND a device hash ----
 *
 * Either alone has a hole. The cookie alone would survive clearing the app's
 * local storage, so a phone whose app lock had been wiped would go on skipping
 * the code with nothing left protecting it. The token alone would travel: copied
 * into another browser's storage, it would be enough. Requiring both means the
 * trust lasts exactly as long as the lock it stands for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The hrms_device cookie, hashed. Never the cookie itself: holding
            // that value is what makes a browser this browser.
            $table->string('device_hash', 64);

            // The bearer token, hashed with the app key so that a stolen dump of
            // this table is not a set of usable tokens.
            $table->string('token_hash', 64);

            $table->timestamp('armed_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            /*
             * One trust per account per browser. Setting the PIN again replaces
             * the row rather than leaving a trail of tokens that all still work.
             *
             * It is also the only index this table needs: the sign-in lookup
             * knows the account and reads the browser from its cookie, so these
             * two columns find the single candidate row and the token is then
             * compared against it. A second index leading with the same pair
             * would be read by nothing.
             */
            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_mobile_devices');
    }
};
