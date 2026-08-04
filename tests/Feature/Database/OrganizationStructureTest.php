<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_organization_data_can_be_seeded(): void
    {
        $this->seed();

        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('departments', 38);

        // Four core positions from the organization seeder plus the Staff Nurse
        // role the nursing roster is built on.
        $this->assertDatabaseCount('positions', 5);

        // Four founding accounts (administrator, HR manager, nursing head, HR
        // employee) plus the twenty seeded nursing staff.
        $this->assertDatabaseCount('users', 24);
        $this->assertDatabaseCount('employees', 24);

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'HR-2026-0001',
            'employment_status' => 'active',
        ]);
    }
}
