<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            OrganizationSeeder::class,
            InitialUserSeeder::class,
            AttendanceSeeder::class,
            ShiftScheduleSeeder::class,
            LeaveManagementSeeder::class,
            NursingStaffSeeder::class,
            SamplePositionStaffSeeder::class,
            EmptyDepartmentStaffSeeder::class,
        ]);
    }
}
