<?php

namespace Tests\Feature\Analytics;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_hr_manager_can_view_analytics_charts(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get('/analytics')
            ->assertOk()
            ->assertSee('Workforce Analytics')
            ->assertSee('Attendance trend')
            ->assertSee('Workforce performance');
    }

    public function test_database_cached_analytics_can_be_read_on_later_requests(): void
    {
        config(['cache.default' => 'database']);
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get('/analytics')->assertOk();
        $this->actingAs($manager)->get('/analytics')
            ->assertOk()
            ->assertSee('Attendance trend');
    }

    public function test_hr_manager_can_download_analytics_csv(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get('/analytics/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_standard_employee_cannot_view_analytics(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get('/analytics')->assertForbidden();
    }

    public function test_adherence_metrics_compute_from_attendance_records(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        [$a, $b, $c, $d] = Employee::query()->where('employment_status', 'active')->orderBy('id')->limit(4)->get();
        $date = '2026-09-20';

        $this->record($a->id, $date, [
            'binding_source' => 'scheduled', 'schedule_status' => 'on_shift', 'status' => 'present',
            'shift_start_at' => "$date 08:00:00", 'shift_end_at' => "$date 18:00:00", 'worked_minutes' => 580,
        ]);
        $this->record($b->id, $date, [
            'binding_source' => 'scheduled', 'schedule_status' => 'late', 'status' => 'late',
            'shift_start_at' => "$date 08:00:00", 'shift_end_at' => "$date 16:00:00", 'worked_minutes' => 460,
        ]);
        $this->record($c->id, $date, [
            'binding_source' => 'override', 'schedule_status' => 'unscheduled', 'status' => 'present',
        ]);
        $this->record($d->id, $date, [
            'binding_source' => 'override', 'schedule_status' => 'unscheduled', 'status' => 'present',
            'override_authorised_by' => $manager->id, 'override_reason' => 'Bank staff covering an unpublished shift.',
        ]);

        $response = $this->actingAs($manager)->get("/analytics?date_from={$date}&date_to={$date}");
        $response->assertOk();
        $metrics = $response->viewData('metrics');

        // 1 of 2 bound (scheduled) records is on_shift.
        $this->assertSame(50.0, $metrics['schedule_adherence_rate']);
        // 2 of 4 total records matched no published shift.
        $this->assertSame(50.0, $metrics['off_shift_rate']);
        // 1 of 4 total records was manager-authorised.
        $this->assertSame(25.0, $metrics['override_rate']);
        // Both bound records ran 20 minutes under their rostered length.
        $this->assertSame(-0.3, $metrics['plan_vs_actual_variance_hours']);
    }

    /** @param array<string, mixed> $overrides */
    private function record(int $employeeId, string $date, array $overrides): void
    {
        AttendanceRecord::query()->create(array_merge([
            'employee_id' => $employeeId,
            'attendance_date' => $date,
            'check_in_at' => "$date 08:00:00",
            'check_in_method' => 'manual',
            'status' => 'present',
        ], $overrides));
    }
}
