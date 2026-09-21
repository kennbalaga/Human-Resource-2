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
            // After the shifts, because a room's dark shifts are recorded
            // against them.
            HospitalRoomSeeder::class,
            LeaveManagementSeeder::class,
            NursingStaffSeeder::class,
            SamplePositionStaffSeeder::class,
            EmptyDepartmentStaffSeeder::class,
            // After the staff seeders, because it reads the workforce they
            // create to fill in gender and the demo solo parent IDs.
            EmployeeDemographicsSeeder::class,
            // Must stay last: it reads the whole seeded workforce to derive
            // reporting lines from department + seniority rank.
            ReportingLineSeeder::class,
        ]);
    }
}
