<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $employees = DB::table('employees')
                ->select(['id', 'employee_number', 'hire_date', 'created_at'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($employees as $employee) {
                if (preg_match('/^([A-Z0-9]+)-(\d{4})$/', $employee->employee_number, $matches) !== 1) {
                    continue;
                }

                $hireYear = substr((string) ($employee->hire_date ?? $employee->created_at), 0, 4);

                if (! ctype_digit($hireYear)) {
                    continue;
                }

                $employeeNumber = $matches[1].'-'.$hireYear.'-'.$matches[2];

                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['employee_number' => $employeeNumber]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $employees = DB::table('employees')
                ->select(['id', 'employee_number'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($employees as $employee) {
                if (preg_match('/^([A-Z0-9]+)-\d{4}-(\d{4})$/', $employee->employee_number, $matches) !== 1) {
                    continue;
                }

                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['employee_number' => $matches[1].'-'.$matches[2]]);
            }
        });
    }
};
