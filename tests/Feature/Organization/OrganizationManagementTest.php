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

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_pages_require_authentication(): void
    {
        $this->get('/organization')->assertRedirect('/login');
        $this->get('/employees')->assertRedirect('/login');
        $this->get('/departments')->assertRedirect('/login');
        $this->get('/positions')->assertRedirect('/login');
    }

    public function test_sidebar_uses_one_organization_entry_and_workspace_tabs_preserve_each_resource_page(): void
    {
        $this->seed();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $sidebarResponse = $this->actingAs($manager)->get(route('schedules.index'));
        $sidebarResponse
            ->assertOk()
            ->assertSee(route('organization.index'), false)
            ->assertDontSee(route('employees.index'), false)
            ->assertDontSee(route('departments.index'), false)
            ->assertDontSee(route('positions.index'), false);

        $this->actingAs($manager)->get(route('organization.index'))
            ->assertOk()
            ->assertSee('Employee directory')
            ->assertSee('Organization workspace')
            ->assertSee('People, accounts, and reporting lines')
            ->assertSee(route('departments.index'), false)
            ->assertSee(route('positions.index'), false);

        $this->actingAs($manager)->get(route('departments.index'))
            ->assertOk()
            ->assertSee('Hospital units and workforce capacity')
            ->assertSee('aria-current="page"', false);
        $this->actingAs($manager)->get(route('positions.index'))
            ->assertOk()
            ->assertSee('Approved roles and department alignment')
            ->assertSee('aria-current="page"', false);
    }

    public function test_authenticated_employees_can_view_the_organization_but_cannot_manage_it(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($user)->get(route('employees.index'))->assertOk()->assertSee('Employee directory')->assertDontSee('Add employee');
        $this->actingAs($user)->get(route('departments.index'))->assertOk()->assertSee('Departments')->assertDontSee('Add department');
        $this->actingAs($user)->get(route('positions.index'))->assertOk()->assertSee('Positions')->assertDontSee('Add position');

        $this->actingAs($user)->get(route('employees.create'))->assertForbidden();
        $this->actingAs($user)->get(route('departments.create'))->assertForbidden();
        $this->actingAs($user)->get(route('positions.create'))->assertForbidden();
    }

    public function test_manager_navigation_and_dashboard_actions_open_real_organization_pages(): void
    {
        $this->seed();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $response = $this->actingAs($manager)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee(route('employees.index'), false)
            ->assertSee(route('departments.index'), false)
            ->assertSee(route('positions.index'), false)
            ->assertSee(route('employees.create'), false)
            ->assertSee('Add employee');

        $this->actingAs($manager)->get(route('employees.create'))->assertOk()->assertSee('Create employee');
        $this->actingAs($manager)->get(route('departments.create'))->assertOk()->assertSee('Create department');
        $this->actingAs($manager)->get(route('positions.create'))->assertOk()->assertSee('Create position');
    }

    public function test_manager_can_open_position_edit_even_when_its_department_is_inactive(): void
    {
        $this->seed();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $position = Position::query()->with('department')->where('code', 'HR-MGR')->firstOrFail();
        $position->department->update(['is_active' => false]);

        $this->actingAs($manager)->get(route('positions.edit', $position))
            ->assertOk()
            ->assertSee('Edit '.$position->title)
            ->assertSee($position->code)
            ->assertSee($position->department->name);
    }

    public function test_manager_can_create_department_position_and_employee_with_secure_onboarding_email(): void
    {
        Notification::fake();
        $this->seed();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->post(route('departments.store'), [
            'code' => 'lab',
            'name' => 'Laboratory Services',
            'description' => 'Diagnostic laboratory operations.',
            'is_active' => '1',
        ])->assertRedirect();

        $department = Department::query()->where('code', 'LAB')->firstOrFail();

        $this->actingAs($manager)->post(route('positions.store'), [
            'department_id' => $department->id,
            'code' => 'lab-tech',
            'title' => 'Medical Laboratory Technician',
            'description' => 'Performs diagnostic laboratory procedures.',
            'is_active' => '1',
        ])->assertRedirect();

        $position = Position::query()->where('code', 'LAB-TECH')->firstOrFail();

        $response = $this->actingAs($manager)->post(route('employees.store'), [
            'employee_number' => 'lab-0001',
            'email' => 'new.lab@hrms.local',
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employment_status' => 'active',
            'hire_date' => '2026-07-16',
            'contact_number' => '+63 917 000 0000',
        ]);

        $employee = Employee::query()->where('employee_number', 'LAB-0001')->firstOrFail();
        $newUser = User::query()->where('email', 'new.lab@hrms.local')->firstOrFail();

        $response->assertRedirect(route('employees.show', $employee));
        $this->assertTrue($newUser->roles()->where('slug', 'employee')->exists());
        $this->assertSame($department->id, $employee->department_id);
        $this->assertSame($position->id, $employee->position_id);
        Notification::assertSentTo($newUser, ResetPassword::class);
    }

    public function test_employee_position_must_belong_to_the_selected_department(): void
    {
        $this->seed();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $position = Position::query()->where('is_active', true)->firstOrFail();
        $otherDepartment = Department::query()->where('is_active', true)->whereKeyNot($position->department_id)->firstOrFail();

        $response = $this->actingAs($manager)->from(route('employees.create'))->post(route('employees.store'), [
            'employee_number' => 'TEST-0001',
            'email' => 'mismatch@hrms.local',
            'first_name' => 'Position',
            'last_name' => 'Mismatch',
            'department_id' => $otherDepartment->id,
            'position_id' => $position->id,
            'employment_status' => 'active',
            'hire_date' => '2026-07-16',
        ]);

        $response->assertRedirect(route('employees.create'))->assertSessionHasErrors('position_id');
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@hrms.local']);
    }

    public function test_updating_employment_status_disables_account_access(): void
    {
        $this->seed();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = Employee::query()->whereNotNull('user_id')->where('employee_number', 'HR-0002')->firstOrFail();

        $this->actingAs($manager)->put(route('employees.update', $employee), [
            'employee_number' => $employee->employee_number,
            'email' => $employee->user->email,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'suffix' => $employee->suffix,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'supervisor_id' => $employee->supervisor_id,
            'employment_status' => 'terminated',
            'hire_date' => $employee->hire_date?->format('Y-m-d'),
            'contact_number' => $employee->contact_number,
            'address' => $employee->address,
        ])->assertRedirect(route('employees.show', $employee));

        $this->assertFalse($employee->user->fresh()->is_active);
        $this->assertSame('terminated', $employee->fresh()->employment_status);
    }
}
