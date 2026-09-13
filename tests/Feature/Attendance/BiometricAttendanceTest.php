<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\AttendanceCaptureSettings;
use App\Services\AttendanceService;
use App\Services\BiometricAttendanceGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiometricAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_existing_manual_attendance_remains_available_in_default_hybrid_mode(): void
    {
        $employeeUser = $this->employeeUser();

        $this->actingAs($employeeUser)->post(route('attendance.check-in'), [
            'office_location_id' => OfficeLocation::query()->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employeeUser->employee->id,
            'check_in_method' => 'manual',
            'check_in_biometric_device_id' => null,
        ]);
    }

    public function test_system_administrator_can_disable_manual_website_attendance(): void
    {
        app(AttendanceCaptureSettings::class)->update($this->adminUser(), 'biometric_only');

        $this->assertDatabaseHas('attendance_settings', ['capture_mode' => 'biometric_only']);

        $employeeUser = $this->employeeUser();
        $this->actingAs($employeeUser)->post(route('attendance.check-in'), [
            'office_location_id' => OfficeLocation::query()->value('id'),
        ])->assertSessionHasErrors('attendance');

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employeeUser->employee->id,
            'attendance_date' => now()->toDateString(),
        ]);
    }

    public function test_standard_employee_cannot_change_the_attendance_capture_mode(): void
    {
        $this->actingAs($this->employeeUser())->patch(route('settings.attendance-capture.update'), [
            'capture_mode' => 'biometric_only',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_settings', 0);
    }

    public function test_system_administrator_can_render_attendance_controls_and_simulator(): void
    {
        $this->actingAs($this->adminUser())->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Attendance capture mode')
            ->assertSee('Emergency mode expiry (Philippine time)')
            ->assertDontSee('Display timezone')
            ->assertSee('Biometric scanner simulator');
    }

    public function test_emergency_expiry_input_is_interpreted_as_philippine_time(): void
    {
        $localExpiry = now('Asia/Manila')->addMinutes(10)->startOfMinute();
        $input = $localExpiry->format('Y-m-d\TH:i');

        $this->actingAs($this->adminUser())->patch(route('settings.attendance-capture.update'), [
            'capture_mode' => AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'manual_mode_reason' => 'Testing a temporary scanner outage.',
            'manual_mode_expires_at' => $input,
        ])->assertSessionHas('success');

        $savedExpiry = AttendanceSetting::query()->firstOrFail()->manual_mode_expires_at;

        $this->assertSame($input, $savedExpiry->timezone('Asia/Manila')->format('Y-m-d\TH:i'));
    }

    public function test_settings_page_exposes_emergency_expiry_for_automatic_mode_sync(): void
    {
        $expiry = now()->addMinutes(10)->startOfSecond();
        app(AttendanceCaptureSettings::class)->update(
            $this->adminUser(),
            AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'Temporary device outage.',
            $expiry,
        );

        $this->actingAs($this->adminUser())->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('data-attendance-settings-sync', false)
            ->assertSee('data-attendance-capture-state="emergency_manual:'.$expiry->getTimestamp().'"', false)
            ->assertSee('data-attendance-state-url="'.route('attendance.state').'"', false)
            ->assertSee('data-manual-mode-expires-at="'.$expiry->getTimestamp().'"', false);

        $this->travel(11)->minutes();

        $this->actingAs($this->adminUser())->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('data-attendance-capture-state="biometric_only:"', false)
            ->assertDontSee('data-manual-mode-expires-at')
            ->assertSee('value="biometric_only" checked', false)
            ->assertDontSee('Temporary device outage.');
    }

    public function test_emergency_manual_mode_requires_a_reason_on_each_manual_entry(): void
    {
        app(AttendanceCaptureSettings::class)->update(
            $this->adminUser(),
            AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'The entrance biometric terminal is offline.',
            now()->addHour(),
        );

        $employeeUser = $this->employeeUser();
        $officeId = OfficeLocation::query()->value('id');

        $this->actingAs($employeeUser)->post(route('attendance.check-in'), [
            'office_location_id' => $officeId,
        ])->assertSessionHasErrors('notes');

        $this->actingAs($employeeUser)->post(route('attendance.check-in'), [
            'office_location_id' => $officeId,
            'notes' => 'Scanner could not read my fingerprint.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employeeUser->employee->id,
            'check_in_method' => 'manual',
            'notes' => 'Scanner could not read my fingerprint.',
        ]);
    }

    public function test_expired_emergency_mode_fails_closed_to_biometric_only(): void
    {
        $settings = app(AttendanceCaptureSettings::class);
        $settings->update(
            $this->adminUser(),
            AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'Temporary device outage.',
            now()->addMinute(),
        );

        $this->travel(2)->minutes();

        $this->assertSame(AttendanceCaptureSettings::BIOMETRIC_ONLY, $settings->mode());
        $this->assertFalse($settings->manualAllowed());
        $this->assertTrue($settings->biometricAllowed());
    }

    public function test_attendance_page_exposes_emergency_expiry_for_automatic_mode_sync(): void
    {
        $expiry = now()->addMinutes(10)->startOfSecond();
        app(AttendanceCaptureSettings::class)->update(
            $this->adminUser(),
            AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'Temporary device outage.',
            $expiry,
        );

        $this->actingAs($this->employeeUser())->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('data-manual-mode-expires-at="'.$expiry->getTimestamp().'"', false)
            ->assertSee('data-attendance-capture-state="emergency_manual:'.$expiry->getTimestamp().'"', false)
            ->assertSee('data-attendance-state-url="'.route('attendance.state').'"', false)
            ->assertSee('Check in now');
    }

    public function test_attendance_state_endpoint_detects_an_administrator_mode_change(): void
    {
        $employee = $this->employeeUser();

        $this->actingAs($employee)->getJson(route('attendance.state'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'state' => 'hybrid:',
                'mode' => AttendanceCaptureSettings::HYBRID,
                'expires_at' => null,
            ]);

        $expiry = now()->addMinutes(10)->startOfSecond();
        app(AttendanceCaptureSettings::class)->update(
            $this->adminUser(),
            AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'Temporary device outage.',
            $expiry,
        );

        $this->actingAs($employee)->getJson(route('attendance.state'))
            ->assertOk()
            ->assertJson([
                'state' => 'emergency_manual:'.$expiry->getTimestamp(),
                'mode' => AttendanceCaptureSettings::EMERGENCY_MANUAL,
                'expires_at' => $expiry->toIso8601String(),
            ]);
    }

    public function test_expired_emergency_mode_renders_without_manual_controls_or_refresh_marker(): void
    {
        app(AttendanceCaptureSettings::class)->update(
            $this->adminUser(),
            AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'Temporary device outage.',
            now()->addMinute(),
        );

        $this->travel(2)->minutes();

        $this->actingAs($this->employeeUser())->get(route('attendance.index'))
            ->assertOk()
            ->assertDontSee('data-manual-mode-expires-at')
            ->assertDontSee('Check in now')
            ->assertSee('Record your time at a biometric terminal');
    }

    public function test_local_simulator_processes_a_device_identified_employee(): void
    {
        $admin = $this->adminUser();
        $employee = $this->employeeUser()->employee;

        $this->actingAs($admin)->patch(route('settings.attendance-capture.update'), [
            'capture_mode' => 'biometric_only',
        ]);

        $this->actingAs($admin)->post(route('settings.biometric-simulator.store'), [
            'employee_id' => $employee->id,
            'event_type' => 'check_in',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'check_in_method' => 'biometric',
        ]);
        $this->assertDatabaseHas('biometric_scan_events', [
            'employee_id' => $employee->id,
            'event_type' => 'check_in',
            'status' => 'processed',
        ]);
    }

    public function test_check_in_and_check_out_capture_sources_are_independent(): void
    {
        $employeeUser = $this->employeeUser();
        $officeId = OfficeLocation::query()->value('id');

        $this->actingAs($employeeUser)->post(route('attendance.check-in'), [
            'office_location_id' => $officeId,
        ])->assertSessionHasNoErrors();

        $device = BiometricDevice::query()->create([
            'office_location_id' => $officeId,
            'code' => 'mixed-source-test',
            'name' => 'Mixed Source Test Device',
            'provider' => 'test',
            'is_active' => true,
        ]);
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employeeUser->employee->id,
            'external_user_id' => 'mixed-source-user',
            'is_active' => true,
        ]);
        app(BiometricAttendanceGateway::class)->receive(
            $device,
            'mixed-source-check-out',
            'mixed-source-user',
            'check_out',
            now(),
        );

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employeeUser->employee->id,
            'check_in_method' => 'manual',
            'check_out_method' => 'biometric',
        ]);
        $this->assertSame(1, AttendanceRecord::query()
            ->whereNotNull('check_out_method')
            ->whereColumn('check_in_method', '!=', 'check_out_method')
            ->count());
    }

    public function test_retried_provider_event_is_idempotent(): void
    {
        $employee = $this->employeeUser()->employee;
        $device = BiometricDevice::query()->create([
            'office_location_id' => OfficeLocation::query()->value('id'),
            'code' => 'test-front-door',
            'name' => 'Test Front Door',
            'provider' => 'test',
            'is_active' => true,
        ]);
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => 'vendor-user-123',
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        $gateway = app(BiometricAttendanceGateway::class);
        $first = $gateway->receive($device, 'provider-event-1', 'vendor-user-123', 'check_in', now());
        $retry = $gateway->receive($device, 'provider-event-1', 'vendor-user-123', 'check_in', now());

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('biometric_scan_events', 1);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_reports_can_filter_and_display_mixed_capture_sources(): void
    {
        $employee = $this->employeeUser()->employee;
        $office = OfficeLocation::query()->firstOrFail();
        app(AttendanceService::class)->checkIn(
            $employee,
            $office,
            null,
            '127.0.0.1',
            'test',
            occurredAt: now()->subMinute(),
        );

        $device = BiometricDevice::query()->create([
            'office_location_id' => $office->id,
            'code' => 'report-filter-device',
            'name' => 'Report Filter Device',
            'provider' => 'test',
            'is_active' => true,
        ]);
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => 'report-filter-user',
            'is_active' => true,
        ]);
        app(BiometricAttendanceGateway::class)->receive(
            $device,
            'report-filter-check-out',
            'report-filter-user',
            'check_out',
            now(),
        );

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'check_in_method' => 'manual',
            'check_out_method' => 'biometric',
        ]);

        $attendanceDate = AttendanceRecord::query()->firstOrFail()->attendance_date->toDateString();

        $this->actingAs(User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail())
            ->get(route('attendance.reports.index', [
                'capture_method' => 'mixed',
                'date_from' => $attendanceDate,
                'date_to' => $attendanceDate,
            ]))
            ->assertOk()
            ->assertSee('Report Filter Device')
            ->assertSee($employee->full_name);
    }

    private function adminUser(): User
    {
        return User::query()->where('email', 'admin@hrms.local')->firstOrFail();
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
