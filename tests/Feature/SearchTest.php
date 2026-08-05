<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_requires_authentication(): void
    {
        $this->get(route('search.index'))->assertRedirect('/login');
    }

    public function test_global_search_returns_matching_employees_and_departments(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $department = Department::query()->where('code', 'HR')->firstOrFail();
        $employee = Employee::query()->where('employee_number', 'HR-2026-0002')->firstOrFail();

        // "HR" matches the shared hrms.local mail domain, so every seeded account
        // qualifies and the results list is capped before this employee appears.
        // Searching the ID keeps the employee half of the assertion deterministic.
        $this->actingAs($user)->get(route('search.index', ['q' => $employee->employee_number]))
            ->assertOk()
            ->assertSee('Search results')
            ->assertSee($employee->full_name)
            ->assertSee(route('employees.show', $employee), false);

        $this->actingAs($user)->get(route('search.index', ['q' => 'HR']))
            ->assertOk()
            ->assertSee('Search results')
            ->assertSee($department->name)
            ->assertSee(route('departments.index', ['search' => $department->name]), false);
    }

    public function test_topbar_search_is_a_real_form_without_a_visible_keyboard_hint(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('action="'.route('search.index').'"', false)
            ->assertSee('name="q"', false)
            ->assertDontSee('⌘ K');
    }
}
