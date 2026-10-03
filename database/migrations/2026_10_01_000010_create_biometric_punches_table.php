<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('biometric_punches')) {
            Schema::create('biometric_punches', function (Blueprint $table) {
                $table->id();

                // The bridge agent's own sha256(serial|pin|timestamp|punch_code)
                // digest. Unique, because the agent only marks a punch as
                // delivered once this application confirms it: a lost response
                // means the same batch is sent again, and the second arrival
                // must land on this row rather than beside it.
                $table->string('fingerprint', 64)->unique();

                // Kept as the serial the device reported rather than as a
                // foreign key: a punch is a statement about what a terminal
                // said, and it stays true after that terminal is retired from
                // biometric_devices.
                $table->string('device_sn', 120)->index();

                // The numeric PIN the terminal knows the person by. Resolved to
                // an employee through biometric_enrollments.external_user_id,
                // which may not exist yet when the punch arrives -- hence the
                // nullable employee_id below.
                $table->string('pin', 120);
                $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

                // Stored UTC, as everywhere else. The device reports local wall
                // clock time, which is converted on the way in.
                $table->dateTime('punched_at');

                $table->unsignedTinyInteger('punch_code');
                $table->unsignedTinyInteger('verify_mode')->nullable();

                $table->dateTime('received_at');
                $table->dateTime('processed_at')->nullable();
                $table->string('processing_status', 30)->default('pending')->index();
                $table->string('failure_reason', 500)->nullable();

                // The derived attendance side of this punch. Nulled rather than
                // cascaded if the scan event is ever removed, so the raw punch
                // outlives anything built on top of it.
                $table->foreignId('biometric_scan_event_id')->nullable()
                    ->constrained('biometric_scan_events')->nullOnDelete();

                $table->timestamps();

                // Answers "what did this person do that day", which is the
                // query HR reaches for when disputing a derived record.
                $table->index(['pin', 'punched_at'], 'biometric_punches_pin_punched_idx');

                // Answers "what is still waiting to be processed", for the
                // replay path after a punch_code mapping correction.
                $table->index(['processing_status', 'punched_at'], 'biometric_punches_status_punched_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_punches');
    }
};
