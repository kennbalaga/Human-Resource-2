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
        $this->assertDatabaseCount('departments', 6);
        $this->assertDatabaseCount('positions', 4);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('employees', 4);

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'HR-0001',
            'employment_status' => 'active',
        ]);
    }
}
