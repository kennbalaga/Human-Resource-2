<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['code' => 'ADMIN', 'name' => 'Administration'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'HR', 'name' => 'Human Resources'],
            ['code' => 'IT', 'name' => 'Information Technology'],
            ['code' => 'MED', 'name' => 'Medical Services'],
            ['code' => 'NUR', 'name' => 'Nursing Service'],
        ];

        foreach ($departments as $department) {
            Department::query()->updateOrCreate(
                ['code' => $department['code']],
                $department + ['is_active' => true],
            );
        }

        $positions = [
            ['department' => 'IT', 'code' => 'SYS-ADMIN', 'title' => 'System Administrator'],
            ['department' => 'HR', 'code' => 'HR-MGR', 'title' => 'HR Manager'],
            ['department' => 'HR', 'code' => 'HR-OFFICER', 'title' => 'HR Officer'],
            ['department' => 'NUR', 'code' => 'NUR-HEAD', 'title' => 'Nursing Department Head'],
        ];

        foreach ($positions as $position) {
            $departmentId = Department::query()
                ->where('code', $position['department'])
                ->firstOrFail()
                ->id;

            Position::query()->updateOrCreate(
                ['code' => $position['code']],
                [
                    'department_id' => $departmentId,
                    'title' => $position['title'],
                    'is_active' => true,
                ],
            );
        }
    }
}
