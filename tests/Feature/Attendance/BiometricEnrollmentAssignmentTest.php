<?php

namespace Tests\Feature\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Services\Attendance\BiometricEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BiometricEnrollmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private BiometricEnrollmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->service = app(BiometricEnrollmentService::class);
    }

    public function test_assigning_a_pin_reserves_the_employee_id_without_claiming_a_capture(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $enrollment = $this->service->assign($device, $employee);

        $this->assertSame((string) $employee->id, $enrollment->external_user_id);
        $this->assertTrue($enrollment->is_active);
        // The state the whole design turns on: reserved, not captured.
        $this->assertNull($enrollment->enrolled_at);
    }

    public function test_bulk_assign_covers_every_active_employee_and_skips_the_archived(): void
    {
        $device = $this->device();

        $archived = $this->activeEmployee();
        $archived->forceFill(['archived_at' => now()])->save();

        $expected = Employee::query()->where('employment_status', 'active')->notArchived()->count();

        $result = $this->service->assignAllActive($device);

        $this->assertSame($expected, $result['total']);
        $this->assertDatabaseMissing('biometric_enrollments', [
            'biometric_device_id' => $device->id,
            'employee_id' => $archived->id,
        ]);
    }

    public function test_running_bulk_assign_twice_preserves_captured_templates_and_creates_no_duplicates(): void
    {
        $device = $this->device();
        $this->service->assignAllActive($device);

        $captured = BiometricEnrollment::query()->where('biometric_device_id', $device->id)->firstOrFail();
        $this->service->markCaptured($captured);
        $capturedAt = $captured->refresh()->enrolled_at;
        $countAfterFirst = BiometricEnrollment::query()->count();

        $this->service->assignAllActive($device);

        // The upsert update-column guard: re-running must reactivate rows and
        // nothing else.
        $this->assertSame($countAfterFirst, BiometricEnrollment::query()->count());
        $this->assertNotNull($captured->refresh()->enrolled_at);
        $this->assertSame($capturedAt->toDateTimeString(), $captured->refresh()->enrolled_at->toDateTimeString());
    }

    public function test_bulk_assign_names_a_legacy_pin_that_is_not_the_employee_id_instead_of_colliding(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        // The hand-written row from before this page existed. A naive upsert on
        // (device, external_user_id) would insert a second row for this
        // employee and detonate the device/employee unique index mid-batch.
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => '4417',
            'is_active' => true,
        ]);

        try {
            $this->service->assignAllActive($device);
            $this->fail('A non-conforming PIN should stop the bulk assign.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('4417', $exception->validator->errors()->first());
        }

        // Nothing half-applied.
        $this->assertDatabaseCount('biometric_enrollments', 1);
    }

    public function test_marking_a_template_as_removed_deactivates_without_deleting_the_row(): void
    {
        $device = $this->device();
        $enrollment = $this->service->assign($device, $this->activeEmployee());

        $this->service->deactivate($enrollment);

        $this->assertFalse($enrollment->refresh()->is_active);
        // The row is the only record the PIN was ever issued to this person.
        $this->assertDatabaseHas('biometric_enrollments', ['id' => $enrollment->id]);
    }

    public function test_a_retired_enrollment_can_be_taken_up_again(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $enrollment = $this->service->assign($device, $employee);
        $this->service->deactivate($enrollment);

        $again = $this->service->assign($device, $employee);

        // Same row, reactivated -- not a second one fighting the unique index.
        $this->assertSame($enrollment->id, $again->id);
        $this->assertTrue($again->is_active);
        $this->assertDatabaseCount('biometric_enrollments', 1);
    }

    public function test_releasing_a_capture_keeps_the_pin_reserved(): void
    {
        $device = $this->device();
        $enrollment = $this->service->assign($device, $this->activeEmployee());
        $this->service->markCaptured($enrollment);

        // A failed or injured finger has to be captured again.
        $this->service->markNotCaptured($enrollment);

        $this->assertNull($enrollment->refresh()->enrolled_at);
        $this->assertTrue($enrollment->refresh()->is_active);
        $this->assertNotNull($enrollment->refresh()->external_user_id);
    }

    public function test_a_terminated_employee_cannot_be_assigned_a_pin(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $employee->forceFill(['employment_status' => 'terminated'])->save();

        $this->expectException(ValidationException::class);
        $this->service->assign($device, $employee->refresh());
    }

    public function test_an_archived_employee_cannot_be_assigned_a_pin(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $employee->forceFill(['archived_at' => now()])->save();

        $this->expectException(ValidationException::class);
        $this->service->assign($device, $employee->refresh());
    }

    public function test_an_employee_id_longer_than_the_terminal_can_hold_is_refused(): void
    {
        config(['attendance.biometric_bridge.max_pin_length' => 1]);
        $device = $this->device();
        $employee = Employee::query()->where('employment_status', 'active')->where('id', '>', 9)->first();

        if ($employee === null) {
            $this->markTestSkipped('The seeded data has no employee id above 9 to exercise the length guard.');
        }

        $this->expectException(ValidationException::class);
        $this->service->assign($device, $employee);
    }

    public function test_captured_templates_for_departed_staff_are_listed_for_removal(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $enrollment = $this->service->assign($device, $employee);
        $this->service->markCaptured($enrollment);

        $this->assertCount(0, $this->service->templatesToRemove($device));

        $employee->forceFill(['employment_status' => 'terminated'])->save();

        // Not a security gap -- the gateway already refuses their punches. It is
        // outstanding physical work: the template is still on the device.
        $worklist = $this->service->templatesToRemove($device);
        $this->assertCount(1, $worklist);
        $this->assertSame($enrollment->id, $worklist->first()->id);
    }

    public function test_a_reserved_pin_that_was_never_captured_is_not_on_the_removal_worklist(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $this->service->assign($device, $employee);
        $employee->forceFill(['employment_status' => 'terminated'])->save();

        // Nothing to go and delete from the terminal: no finger was ever taken.
        $this->assertCount(0, $this->service->templatesToRemove($device));
    }

    private function device(): BiometricDevice
    {
        return BiometricDevice::query()->create([
            'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
            'code' => 'djnrmhs-main-entrance',
            'name' => 'Main Entrance Terminal',
            'provider' => 'zkteco',
            'serial_number' => 'QME2261300147',
            'is_active' => true,
        ]);
    }

    private function activeEmployee(): Employee
    {
        return Employee::query()->where('employment_status', 'active')->notArchived()->firstOrFail();
    }
}
