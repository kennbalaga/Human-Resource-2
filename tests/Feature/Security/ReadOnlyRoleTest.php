<?php

namespace Tests\Feature\Security;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadOnlyRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@hrms.local')->firstOrFail();
    }

    public function test_system_administrator_still_reads_every_workforce_page(): void
    {
        $pages = [
            '/dashboard', '/organization', '/employees', '/departments', '/positions',
            '/schedules', '/shifts', '/timesheets', '/leaves', '/attendance',
            '/analytics', '/audit-logs', '/integrations', '/settings',
        ];

        foreach ($pages as $page) {
            $this->flushSession();
            $this->actingAs($this->admin())->get($page)->assertOk();
        }
    }

    public function test_system_administrator_cannot_write_workforce_data(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = Employee::query()->firstOrFail();
        $shift = Shift::query()->firstOrFail();

        $writes = [
            ['post', '/employees', []],
            ['put', "/employees/{$employee->id}", []],
            ['post', '/departments', []],
            ['put', "/departments/{$department->id}", []],
            ['post', '/positions', []],
            ['post', '/shifts', []],
            ['delete', "/shifts/{$shift->id}", []],
            ['post', '/schedules', []],
            ['post', '/recurring-schedules', []],
            ['post', '/leave-types', []],
        ];

        foreach ($writes as [$method, $uri, $payload]) {
            $this->flushSession();
            $this->actingAs($this->admin())->{$method}($uri, $payload)->assertForbidden();
        }
    }

    public function test_system_administrator_cannot_approve_or_reject_records(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();
        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'reason' => 'Scheduled family vacation leave.',
        ])->assertSessionHasNoErrors();
        $leave = LeaveRequest::query()->firstOrFail();

        $this->flushSession();
        $this->actingAs($this->admin())->post("/leaves/{$leave->id}/approve")->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin())->post("/leaves/{$leave->id}/reject", ['reviewer_notes' => 'No cover'])->assertForbidden();

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_system_administrator_keeps_settings_and_own_self_service(): void
    {
        $this->actingAs($this->admin())
            ->patch('/settings/theme', ['theme' => 'dark'])
            ->assertSessionHasNoErrors();

        $this->flushSession();
        $this->actingAs($this->admin())
            ->patch('/settings/preferences', [
                'timezone' => 'Asia/Manila',
                'theme' => 'dark',
                'email_notifications' => '1',
            ])
            ->assertSessionHasNoErrors();

        // The payload is deliberately empty: reaching validation instead of a 403
        // is what proves the read-only gate let their own check-in through.
        $this->flushSession();
        $this->actingAs($this->admin())
            ->post('/attendance/check-in')
            ->assertSessionHasErrors('office_location_id');
    }

    /**
     * Both of these are gated to system administrators alone, so read-only had to
     * keep them or the capability would belong to nobody at all.
     */
    public function test_system_administrator_keeps_platform_administration(): void
    {
        $this->actingAs($this->admin())
            ->patch('/integrations/ai-scheduling', ['assistant_enabled' => '0'])
            ->assertSessionHasNoErrors();

        $employee = Employee::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'employee@hrms.local'))
            ->firstOrFail();

        // Reaching validation rather than a 403 proves the gate allowed it through.
        $this->flushSession();
        $this->actingAs($this->admin())
            ->post("/employees/{$employee->id}/two-factor/reset")
            ->assertStatus(302);
    }

    public function test_system_administrator_can_still_file_and_cancel_their_own_leave(): void
    {
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();

        $this->actingAs($this->admin())->get('/leaves')->assertOk()->assertSee('Request leave');
        $this->actingAs($this->admin())->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'reason' => 'Scheduled family vacation leave.',
        ])->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();
        $this->assertSame($this->admin()->employee->id, $leave->employee_id);

        $this->flushSession();
        $this->actingAs($this->admin())->post("/leaves/{$leave->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_system_administrator_cannot_cancel_someone_elses_leave(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();
        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'reason' => 'Scheduled family vacation leave.',
        ])->assertSessionHasNoErrors();
        $leave = LeaveRequest::query()->firstOrFail();

        $this->flushSession();
        $this->actingAs($this->admin())->post("/leaves/{$leave->id}/cancel")->assertForbidden();
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_system_administrator_cannot_download_workforce_data(): void
    {
        $range = 'date_from=2026-01-01&date_to=2026-12-31';

        $downloads = [
            '/timesheets/export?'.$range,
            '/attendance/reports/export?'.$range,
            '/attendance/reports/export-excel?'.$range,
            '/attendance/reports/export-pdf?'.$range,
            '/analytics/export?'.$range,
        ];

        foreach ($downloads as $uri) {
            $this->flushSession();
            $this->actingAs($this->admin())->get($uri)->assertForbidden();
        }
    }

    public function test_system_administrator_sees_no_export_buttons(): void
    {
        $this->actingAs($this->admin())->get('/timesheets')->assertOk()->assertDontSee('Export CSV');
        $this->flushSession();
        $this->actingAs($this->admin())->get('/attendance/reports')->assertOk()->assertDontSee('Export as CSV');
        $this->flushSession();
        $this->actingAs($this->admin())->get('/analytics')->assertOk()->assertDontSee('Download report');
    }

    public function test_hr_manager_can_still_download_workforce_data(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $range = 'date_from=2026-01-01&date_to=2026-12-31';

        $this->actingAs($manager)->get('/analytics')->assertOk()->assertSee('Download report');
        $this->flushSession();
        $this->actingAs($manager)->get('/analytics/export?'.$range)->assertOk();
        $this->flushSession();
        $this->actingAs($manager)->get('/timesheets/export?'.$range)->assertOk();
    }

    public function test_read_only_admin_sees_no_write_controls(): void
    {
        $this->actingAs($this->admin())->get('/employees')->assertOk()->assertDontSee('Add employee');
        $this->flushSession();
        $this->actingAs($this->admin())->get('/departments')->assertOk()->assertDontSee('Add department');
        $this->flushSession();
        $this->actingAs($this->admin())->get('/positions')->assertOk()->assertDontSee('Add position');
        $this->flushSession();
        $this->actingAs($this->admin())->get('/shifts')->assertOk()->assertDontSee('New shift');
        $this->flushSession();
        $this->actingAs($this->admin())->get('/leaves')->assertOk()->assertDontSee('Add leave type');
    }

    public function test_read_only_admin_cannot_open_create_or_edit_forms(): void
    {
        $employee = Employee::query()->firstOrFail();
        $department = Department::query()->firstOrFail();

        $this->actingAs($this->admin())->get('/employees/create')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin())->get("/employees/{$employee->id}/edit")->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin())->get('/departments/create')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin())->get("/departments/{$department->id}/edit")->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin())->get('/positions/create')->assertForbidden();
    }

    public function test_hr_manager_write_access_is_untouched(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->post('/leave-types', [
            'code' => 'TESTLEAVE',
            'name' => 'Test Leave',
            'annual_entitlement' => 5,
            'max_carry_over' => 0,
            'color' => '#176B43',
            'is_active' => '1',
            'requires_attachment' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_types', ['code' => 'TESTLEAVE']);
    }
}
