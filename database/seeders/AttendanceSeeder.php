<?php

namespace Database\Seeders;

use App\Models\OfficeLocation;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $location = config('attendance.default_location');

        OfficeLocation::query()->updateOrCreate(
            ['name' => $location['name']],
            [
                'address' => $location['address'],
                'timezone' => $location['timezone'],
                'work_start_time' => $location['work_start_time'],
                'work_end_time' => $location['work_end_time'],
                'grace_period_minutes' => $location['grace_period_minutes'],
                'break_minutes' => $location['break_minutes'],
                'is_active' => true,
            ],
        );
    }
}
