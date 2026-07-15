<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'System Administrator',
                'slug' => 'system-administrator',
                'description' => 'Full system configuration and account administration.',
            ],
            [
                'name' => 'HR Manager',
                'slug' => 'hr-manager',
                'description' => 'Human resources and workforce administration.',
            ],
            [
                'name' => 'Department Head',
                'slug' => 'department-head',
                'description' => 'Department-level employee oversight.',
            ],
            [
                'name' => 'Employee',
                'slug' => 'employee',
                'description' => 'Standard employee self-service access.',
            ],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                $role,
            );
        }
    }
}
