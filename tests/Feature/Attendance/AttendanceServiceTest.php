<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_calculates_late_work_and_overtime_minutes(): void
    {
        $office = OfficeLocation::query()->firstOrFail();
        $office->update([
            'latitude' => 14.7500000,
            'longitude' => 121.0500000,
            'radius_meters' => 200,
            'geofence_enabled' => true,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'grace_period_minutes' => 15,
            'break_minutes' => 60,
        ]);

        $employee = Employee::query()->where('employee_number', 'HR-0001')->firstOrFail();
        $service = app(AttendanceService::class);
        $location = [
            'latitude' => 14.7500000,
            'longitude' => 121.0500000,
            'accuracy' => 8,
            'notes' => null,
        ];

        Carbon::setTestNow(Carbon::parse('2026-07-15 08:20:00', 'Asia/Manila'));
        $checkIn = $service->checkIn($employee, $office, $location, '127.0.0.1', 'PHPUnit');

        $this->assertSame('late', $checkIn->status);
        $this->assertSame(20, $checkIn->late_minutes);
        $this->assertTrue($checkIn->check_in_within_geofence);

        Carbon::setTestNow(Carbon::parse('2026-07-15 17:30:00', 'Asia/Manila'));
        $checkOut = $service->checkOut($employee, $office, $location, '127.0.0.1', 'PHPUnit');

        $this->assertSame(490, $checkOut->worked_minutes);
        $this->assertSame(0, $checkOut->undertime_minutes);
        $this->assertSame(30, $checkOut->overtime_minutes);
        $this->assertNotNull($checkOut->check_out_at);
    }

    public function test_geofence_rejects_a_location_outside_the_radius(): void
    {
        $office = OfficeLocation::query()->firstOrFail();
        $office->update([
            'latitude' => 14.7500000,
            'longitude' => 121.0500000,
            'radius_meters' => 100,
            'geofence_enabled' => true,
        ]);

        $employee = Employee::query()->where('employee_number', 'HR-0001')->firstOrFail();

        $this->expectException(ValidationException::class);

        app(AttendanceService::class)->checkIn(
            $employee,
            $office,
            ['latitude' => 14.7600000, 'longitude' => 121.0500000, 'accuracy' => 5, 'notes' => null],
            '127.0.0.1',
            'PHPUnit',
        );
    }
}
