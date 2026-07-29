<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('slug', [
            'system-administrator',
            'hr-manager',
            'department-head',
        ])->pluck('id', 'slug');
        $rolesByPosition = [
            'SYS-ADMIN' => $roleIds['system-administrator'] ?? null,
            'HR-MGR' => $roleIds['hr-manager'] ?? null,
            'NUR-HEAD' => $roleIds['department-head'] ?? null,
        ];
        $employees = DB::table('employees')
            ->join('positions', 'positions.id', '=', 'employees.position_id')
            ->whereNotNull('employees.user_id')
            ->whereIn('positions.code', array_keys($rolesByPosition))
            ->get(['employees.user_id', 'positions.code']);
        $now = now();

        foreach ($employees as $employee) {
            $roleId = $rolesByPosition[$employee->code];

            if ($roleId === null) {
                continue;
            }

            DB::table('role_user')->where('user_id', $employee->user_id)->delete();
            DB::table('role_user')->insert([
                'role_id' => $roleId,
                'user_id' => $employee->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Role assignments are account-security records and must not be removed by a rollback.
    }
};
