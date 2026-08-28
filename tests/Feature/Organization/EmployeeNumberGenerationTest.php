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

    public function test_new_employee_id_is_generated_from_the_position_and_ignores_tampered_input(): void
    {
        $manager = $this->manager();
        $position = Position::query()->where('code', 'HR-OFFICER')->firstOrFail();
        $department = $position->department;
        // Tracks the generator's next-sequence behaviour rather than the exact
        // size of the seeded HR Officer roster, the same way it does below for
        // the position-independence test.
        $expectedSequence = $this->highestSequenceFor($position->code) + 1;

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

        $response->assertRedirect(route('employees.index', ['employee' => $employee->id]));
        $this->assertSame($this->employeeNumber($position->code, $expectedSequence), $employee->employee_number);
        Notification::assertSentTo($employee->user, ResetPassword::class);
    }

    public function test_position_sequences_are_independent_and_never_reuse_historical_numbers(): void
    {
        // Staff Nurse in Internal Medicine: one of two nursing positions in the
        // same department, so a sequence of its own here is what shows the
        // counter follows the position rather than the department it sits in.
        $position = Position::query()->where('code', 'NUR-STAFF-IM')->firstOrFail();
        $department = $position->department;
        $headNurse = Position::query()->where('code', 'NUR-HEAD-IM')->firstOrFail();

        // Sit the retired number above every seeded employee in this position
        // so the assertion tracks the generator's behaviour rather than the
        // size of the seeded roster.
        $retiredSequence = $this->highestSequenceFor($position->code) + 5;
        Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_number' => $this->employeeNumber($position->code, $retiredSequence),
            'first_name' => 'Historical',
            'last_name' => 'Employee',
            'employment_status' => 'terminated',
        ])->delete();

        $headNurseSequence = $this->highestSequenceFor($headNurse->code) + 1;

        $this->actingAs($this->manager())->post(route('employees.store'), $this->employeePayload(
            $department,
            $position,
            'generated.nurse@hrms.local',
        ))->assertRedirect();
        $this->actingAs($this->manager())->post(route('employees.store'), $this->employeePayload(
            $department,
            $headNurse,
            'generated.head.nurse@hrms.local',
        ))->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_number' => $this->employeeNumber($position->code, $retiredSequence + 1),
            'position_id' => $position->id,
        ]);
        // The Head Nurse alongside it carries on from its own count, untouched
        // by the inflated Staff Nurse sequence.
        $this->assertDatabaseHas('employees', [
            'employee_number' => $this->employeeNumber($headNurse->code, $headNurseSequence),
            'position_id' => $headNurse->id,
        ]);
    }

    public function test_employee_id_uses_the_employee_hire_year(): void
    {
        $position = Position::query()->where('code', 'HR-OFFICER')->firstOrFail();

        $this->actingAs($this->manager())->post(route('employees.store'), array_merge(
            $this->employeePayload($position->department, $position, 'hired.2025@hrms.local'),
            ['hire_date' => '2025-04-15'],
        ))->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'HR-OFFICER-2025-0001',
            'position_id' => $position->id,
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
        // IT was folded into Administrative and General Services by the
        // hospital restructure and no longer exists as its own department.
        $department = Department::query()->where('code', 'ADMIN')->firstOrFail();
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
        $employee = Employee::query()->with('user')->where('employee_number', 'HR-OFFICER-2026-0001')->firstOrFail();

        $this->actingAs($this->manager())->put(route('employees.update', $employee), [
            'employee_number' => 'TAMPERED-0001',
            'email' => $employee->user->email,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'employment_status' => $employee->employment_status,
            'hire_date' => $employee->hire_date?->toDateString(),
        ])->assertRedirect(route('employees.index', ['employee' => $employee->id]));

        $this->assertSame('HR-OFFICER-2026-0001', $employee->fresh()->employee_number);
        $this->actingAs($this->manager())->get(route('employees.edit', $employee))
            ->assertOk()
            ->assertSee('value="HR-OFFICER-2026-0001" readonly', false)
            ->assertDontSee('name="employee_number"', false);
    }

    private function highestSequenceFor(string $positionCode): int
    {
        return (int) Employee::query()
            ->withTrashed()
            ->where('employee_number', 'like', $positionCode.'-2026-%')
            ->pluck('employee_number')
            ->map(fn (string $number): int => (int) substr($number, -4))
            ->max();
    }

    private function employeeNumber(string $positionCode, int $sequence): string
    {
        return sprintf('%s-2026-%04d', $positionCode, $sequence);
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
