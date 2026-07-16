<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guest_cannot_open_attendance_page(): void
    {
        $this->get('/attendance')->assertRedirect('/login');
    }

    public function test_employee_can_view_attendance_capture_page(): void
    {
        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($user)
            ->get('/attendance')
            ->assertOk()
            ->assertSee('Time &amp; Attendance', false)
            ->assertSee('Check in now')
            ->assertDontSee('Location verification')
            ->assertDontSee('Device location verification')
            ->assertDontSee('Google Maps')
            ->assertDontSee('data-google-maps-key', false);
    }

    public function test_employee_can_check_in_without_location_data(): void
    {
        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $response = $this->actingAs($user)->post('/attendance/check-in', [
            'office_location_id' => 1,
        ]);

        $response->assertRedirect('/attendance')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $user->employee->id,
            'office_location_id' => 1,
        ]);
    }

    public function test_hr_manager_can_view_reports_and_export_csv(): void
    {
        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($user)
            ->get('/attendance/reports')
            ->assertOk()
            ->assertSee('Attendance Reports');

        $this->actingAs($user)
            ->get('/attendance/reports/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_standard_employee_cannot_view_management_reports(): void
    {
        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($user)->get('/attendance/reports')->assertForbidden();
    }
}
