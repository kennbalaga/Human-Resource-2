<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employee IDs had drifted into three shapes at once — department-prefixed
 * (OPD-2026-0001), prefixed with a department that no longer exists
 * (NUR-2026-0007), and position-prefixed (NUR-HEAD-OPD-2026-0009). This
 * settles every one of them on the position-prefixed shape that
 * EmployeeNumberGenerator now issues: {POSITION CODE}-{HIRE YEAR}-{SEQUENCE}.
 *
 * IDs that already read that way are left untouched, so only the drifted rows
 * move. Renumbered staff need a fresh attendance badge: the QR signature is
 * bound to the employee ID, so any copy printed under the old ID stops
 * scanning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $employees = DB::table('employees')
                ->join('positions', 'employees.position_id', '=', 'positions.id')
                ->select([
                    'employees.id',
                    'employees.employee_number',
                    'employees.hire_date',
                    'employees.created_at',
                    'positions.code as position_code',
                ])
                ->orderBy('employees.id')
                ->lockForUpdate()
                ->get();

            // Seeded per prefix as the loop runs, so a number handed to one
            // employee is never offered to the next.
            $highest = [];
            foreach ($employees as $employee) {
                $prefix = $employee->position_code.'-'.substr((string) ($employee->hire_date ?? $employee->created_at), 0, 4);

                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{4})$/', $employee->employee_number, $matches) === 1) {
                    $highest[$prefix] = max($highest[$prefix] ?? 0, (int) $matches[1]);
                }
            }

            foreach ($employees as $employee) {
                $hireYear = substr((string) ($employee->hire_date ?? $employee->created_at), 0, 4);

                if (! ctype_digit($hireYear)) {
                    continue;
                }

                $prefix = $employee->position_code.'-'.$hireYear;

                if (preg_match('/^'.preg_quote($prefix, '/').'-\d{4}$/', $employee->employee_number) === 1) {
                    continue;
                }

                $highest[$prefix] = ($highest[$prefix] ?? 0) + 1;

                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['employee_number' => sprintf('%s-%04d', $prefix, $highest[$prefix])]);
            }
        });
    }

    /**
     * Not reversible: the IDs this replaced were prefixed with departments,
     * some of which the hospital restructure has since dissolved, so there is
     * nothing left to derive the old values from.
     */
    public function down(): void
    {
        //
    }
};
