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
        // 16 active departments from the current hospital structure plus the
        // 21 legacy placeholder codes the restructure migration soft-deletes
        // rather than removes (schedule/employee/position history can still
        // point at them).
        $this->assertDatabaseCount('departments', 37);

        // Positions across every seeder: OrganizationSeeder's founding roles,
        // NursingStaffSeeder's per-ward head/staff nurse pairs, and the
        // head/staff pairs SamplePositionStaffSeeder and
        // EmptyDepartmentStaffSeeder add for the rest of the hospital.
        $this->assertDatabaseCount('positions', 32);

        // Every account and employee record left standing once all seeders
        // (founding accounts, nursing roster, sample department staff, and
        // the previously-empty departments) have run.
        $this->assertDatabaseCount('users', 90);
        $this->assertDatabaseCount('employees', 90);

        $this->assertDatabaseHas('employees', [
            'employee_number' => 'HR-2026-0001',
            'employment_status' => 'active',
        ]);
    }
}
