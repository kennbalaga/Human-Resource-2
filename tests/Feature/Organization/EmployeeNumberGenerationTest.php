<?php

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
    }

    public function test_new_employee_id_is_generated_from_the_department_and_ignores_tampered_input(): void
    {
        $manager = $this->manager();
        $department = Department::query()->where('code', 'HR')->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->firstOrFail();

        $this->actingAs($manager)->get(route('employees.create'))
            ->assertOk()
            ->assertSee('data-generated-employee-number', false)
            ->assertDontSee('name="employee_number"', false);

        $response = $this->actingAs($manager)->post(route('employees.store'), $this->employeePayload(
            $department,
            $position,
            'generated.hr@hrms.local',
        ) + ['employee_number' => 'TAMPERED-9999']);

        $employee = Employee::query()->whereHas('user', fn ($query) => $query->where('email', 'generated.hr@hrms.local'))->firstOrFail();

        $response->assertRedirect(route('employees.show', $employee));
        $this->assertSame('HR-2026-0003', $employee->employee_number);
        Notification::assertSentTo($employee->user, ResetPassword::class);
    }

    public function test_department_sequences_are_independent_and_never_reuse_historical_numbers(): void
    {
        $department = Department::query()->where('code', 'NUR')->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->firstOrFail();

        // Sit the retired number above every seeded nurse so the assertion tracks
        // the generator's behaviour rather than the size of the seeded roster.
        $retiredSequence = $this->highestNursingSequence() + 5;
        Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_number' => $this->nursingNumber($retiredSequence),
            'first_name' => 'Historical',
            'last_name' => 'Employee',
            'employment_status' => 'terminated',
        ])->delete();

        $this->actingAs($this->manager())->post(route('employees.store'), $this->employeePayload(
            $department,
            $position,
            'generated.nurse@hrms.local',
        ))->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_number' => $this->nursingNumber($retiredSequence + 1),
            'department_id' => $department->id,
        ]);
    }

    public function test_employee_id_uses_the_employee_hire_year(): void
    {
        $department = Department::query()->where('code', 'HR')->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->firstOrFail();

        $this->actingAs($this->manager())->post(route('employees.store'), array_merge(
            $this->employeePayload($department, $position, 'hired.2025@hrms.local'),
            ['hire_date' => '2025-04-15'],
        ))->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'HR-2025-0001',
            'department_id' => $department->id,
        ]);
    }

    public function test_only_system_administrator_can_change_the_global_generation_policy(): void
    {
        $administrator = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'system-administrator'))->firstOrFail();
        $manager = $this->manager();

        $this->actingAs($manager)->get(route('settings.edit'))
            ->assertOk()
            ->assertDontSee('Employee ID generation');
        $this->actingAs($manager)->patch(route('settings.employee-numbers.update'), ['auto_generate' => '1'])
            ->assertForbidden();

        $this->flushSession();
        $this->actingAs($administrator)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Employee ID generation');
        $this->actingAs($administrator)->patch(route('settings.employee-numbers.update'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Automatic employee ID generation disabled. New employee IDs must be entered manually.');

        $this->assertDatabaseHas('employee_number_settings', [
            'id' => 1,
            'auto_generate' => false,
            'updated_by' => $administrator->id,
        ]);
    }

    public function test_manual_employee_id_is_required_only_when_auto_generation_is_disabled(): void
    {
        $administrator = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'system-administrator'))->firstOrFail();
        $this->actingAs($administrator)->patch(route('settings.employee-numbers.update'))
            ->assertRedirect();
        $this->flushSession();
        $department = Department::query()->where('code', 'IT')->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->firstOrFail();

        $this->actingAs($this->manager())->get(route('employees.create'))
            ->assertOk()
            ->assertSee('name="employee_number"', false)
            ->assertDontSee('data-generated-employee-number', false);
        $this->actingAs($this->manager())->post(route('employees.store'), $this->employeePayload(
            $department,
            $position,
            'manual.id@hrms.local',
        ))->assertSessionHasErrors('employee_number');
        $this->actingAs($this->manager())->post(route('employees.store'), $this->employeePayload(
            $department,
            $position,
            'manual.id@hrms.local',
        ) + ['employee_number' => 'CUSTOM-0100'])->assertRedirect();

        $this->assertDatabaseHas('employees', ['employee_number' => 'CUSTOM-0100']);
    }

    public function test_existing_employee_id_is_immutable_even_when_the_request_is_tampered(): void
    {
        $employee = Employee::query()->with('user')->where('employee_number', 'HR-2026-0002')->firstOrFail();

        $this->actingAs($this->manager())->put(route('employees.update', $employee), [
            'employee_number' => 'TAMPERED-0001',
            'email' => $employee->user->email,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'employment_status' => $employee->employment_status,
            'hire_date' => $employee->hire_date?->toDateString(),
        ])->assertRedirect(route('employees.show', $employee));

        $this->assertSame('HR-2026-0002', $employee->fresh()->employee_number);
        $this->actingAs($this->manager())->get(route('employees.edit', $employee))
            ->assertOk()
            ->assertSee('value="HR-2026-0002" readonly', false)
            ->assertDontSee('name="employee_number"', false);
    }

    private function highestNursingSequence(): int
    {
        return (int) Employee::query()
            ->withTrashed()
            ->where('employee_number', 'like', 'NUR-2026-%')
            ->pluck('employee_number')
            ->map(fn (string $number): int => (int) substr($number, -4))
            ->max();
    }

    private function nursingNumber(int $sequence): string
    {
        return sprintf('NUR-2026-%04d', $sequence);
    }

    /** @return array<string, mixed> */
    private function employeePayload(Department $department, Position $position, string $email): array
    {
        return [
            'email' => $email,
            'first_name' => 'Generated',
            'last_name' => 'Employee',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employment_status' => 'active',
            'hire_date' => '2026-07-17',
        ];
    }

    private function manager(): User
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'hr-manager'))->firstOrFail();
    }
}
