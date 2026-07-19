<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_settings')) {
            Schema::create('attendance_settings', function (Blueprint $table) {
                $table->id();
                $table->string('capture_mode', 30)->default('hybrid');
                $table->text('manual_mode_reason')->nullable();
                $table->timestamp('manual_mode_expires_at')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('biometric_devices')) {
            Schema::create('biometric_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_location_id')->constrained()->restrictOnDelete();
                $table->string('code', 80)->unique();
                $table->string('name');
                $table->string('provider', 80);
                $table->string('serial_number')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('configuration')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('biometric_enrollments')) {
            Schema::create('biometric_enrollments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('biometric_device_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained()->restrictOnDelete();
                $table->string('external_user_id', 120);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('enrolled_at')->nullable();
                $table->timestamps();

                $table->unique(['biometric_device_id', 'external_user_id'], 'biometric_enrollments_device_external_unique');
                $table->unique(['biometric_device_id', 'employee_id'], 'biometric_enrollments_device_employee_unique');
            });
        } else {
            Schema::table('biometric_enrollments', function (Blueprint $table) {
                if (! Schema::hasIndex('biometric_enrollments', 'biometric_enrollments_device_external_unique')) {
                    $table->unique(['biometric_device_id', 'external_user_id'], 'biometric_enrollments_device_external_unique');
                }
                if (! Schema::hasIndex('biometric_enrollments', 'biometric_enrollments_device_employee_unique')) {
                    $table->unique(['biometric_device_id', 'employee_id'], 'biometric_enrollments_device_employee_unique');
                }
            });
        }

        if (! Schema::hasTable('biometric_scan_events')) {
            Schema::create('biometric_scan_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('biometric_device_id')->constrained()->restrictOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider_event_id', 160);
                $table->string('external_user_id', 120)->nullable();
                $table->string('event_type', 20);
                $table->dateTime('captured_at');
                $table->dateTime('received_at');
                $table->string('status', 30)->default('received')->index();
                $table->string('failure_reason', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

            });
        }
        if (! Schema::hasIndex('biometric_scan_events', 'biometric_scan_device_event_unique')) {
            Schema::table('biometric_scan_events', function (Blueprint $table) {
                $table->unique(['biometric_device_id', 'provider_event_id'], 'biometric_scan_device_event_unique');
            });
        }
        if (! Schema::hasIndex('biometric_scan_events', 'biometric_scan_employee_captured_idx')) {
            Schema::table('biometric_scan_events', function (Blueprint $table) {
                $table->index(['employee_id', 'captured_at'], 'biometric_scan_employee_captured_idx');
            });
        }

        if (! Schema::hasColumn('attendance_records', 'check_in_method')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->string('check_in_method', 20)->default('manual')->after('check_in_at');
            });
        }
        if (! Schema::hasColumn('attendance_records', 'check_in_biometric_device_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->foreignId('check_in_biometric_device_id')->nullable()->after('check_in_method')->constrained('biometric_devices')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('attendance_records', 'check_out_method')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->string('check_out_method', 20)->nullable()->after('check_out_at');
            });
        }
        if (! Schema::hasColumn('attendance_records', 'check_out_biometric_device_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->foreignId('check_out_biometric_device_id')->nullable()->after('check_out_method')->constrained('biometric_devices')->nullOnDelete();
            });
        }
        if (! Schema::hasIndex('attendance_records', 'attendance_date_check_in_method_idx')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->index(['attendance_date', 'check_in_method'], 'attendance_date_check_in_method_idx');
            });
        }

        DB::table('attendance_records')
            ->whereNotNull('check_out_at')
            ->update(['check_out_method' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('attendance_date_check_in_method_idx');
            $table->dropConstrainedForeignId('check_in_biometric_device_id');
            $table->dropConstrainedForeignId('check_out_biometric_device_id');
            $table->dropColumn(['check_in_method', 'check_out_method']);
        });

        Schema::dropIfExists('biometric_scan_events');
        Schema::dropIfExists('biometric_enrollments');
        Schema::dropIfExists('biometric_devices');
        Schema::dropIfExists('attendance_settings');
    }
};
