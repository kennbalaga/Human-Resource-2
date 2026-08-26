<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_employee_id_and_password_create_a_sanctum_token(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
            'device_name' => 'Postman tests',
        ])->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.employee_number', 'HR-MGR-2026-0001')
            ->assertJsonStructure(['data' => ['token', 'abilities', 'user']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_work_email_and_password_create_a_sanctum_token(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'employee_id' => 'hr.manager@hrms.local',
            'password' => 'ChangeMe123!',
            'device_name' => 'Postman tests',
        ])->assertCreated()
            ->assertJsonPath('data.user.employee_number', 'HR-MGR-2026-0001');
    }

    public function test_invalid_api_credentials_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/token', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'wrong-password',
            'device_name' => 'Postman tests',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_inactive_employee_cannot_create_an_api_token(): void
    {
        $this->userForEmployee('HR-MGR-2026-0001')->employee()->update(['employment_status' => 'inactive']);

        $this->postJson('/api/v1/auth/token', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
            'device_name' => 'Postman tests',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_api_requires_a_bearer_token(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_manager_can_list_employees_and_security_headers_are_present(): void
    {
        $manager = $this->userForEmployee('HR-MGR-2026-0001');
        Sanctum::actingAs($manager, ['workforce:read', 'workforce:write']);

        // Scoped to HR-OFFICER-2026-0001's own department: the directory now has 90
        // seeded employees, more than fit on the default paginated page, so
        // an unscoped listing can't reliably be expected to surface one
        // specific employee_number.
        $this->getJson('/api/v1/employees?department_id='.$manager->employee->department_id)
            ->assertOk()
            ->assertJsonFragment(['employee_number' => 'HR-OFFICER-2026-0001'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_regular_employee_cannot_list_the_full_employee_directory(): void
    {
        $employee = $this->userForEmployee('HR-OFFICER-2026-0001');
        Sanctum::actingAs($employee, ['workforce:read', 'leave:write', 'timesheet:write']);

        $this->getJson('/api/v1/employees')->assertForbidden();
    }

    public function test_read_only_manager_token_cannot_create_a_schedule(): void
    {
        $manager = $this->userForEmployee('HR-MGR-2026-0001');
        Sanctum::actingAs($manager, ['workforce:read']);

        $this->postJson('/api/v1/schedules', [
            'employee_id' => $this->userForEmployee('HR-OFFICER-2026-0001')->employee->id,
            'shift_id' => 1,
            'work_date' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_production_api_validation_errors_remain_422_responses(): void
    {
        config(['app.debug' => false]);
        $manager = $this->userForEmployee('HR-MGR-2026-0001');
        Sanctum::actingAs($manager, ['workforce:read', 'workforce:write', 'analytics:read']);

        $this->getJson('/api/v1/analytics')->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_authenticated_write_requests_are_audited_without_sensitive_values(): void
    {
        $manager = $this->userForEmployee('HR-MGR-2026-0001');
        Sanctum::actingAs($manager, ['workforce:read', 'workforce:write']);

        $this->deleteJson('/api/v1/auth/token')->assertOk();

        $audit = AuditLog::query()->where('route_name', 'api.v1.auth.logout')->firstOrFail();
        $this->assertSame($manager->id, $audit->user_id);
        $this->assertSame('DELETE', $audit->method);
        $this->assertSame([], $audit->metadata['input_fields']);
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
