<?php

namespace App\Services\Organization;

use App\Models\Department;
use App\Models\Employee;
use LogicException;

class EmployeeNumberGenerator
{
    public function generateForDepartment(int $departmentId): string
    {
        if (! Employee::query()->getConnection()->transactionLevel()) {
            throw new LogicException('Employee numbers must be generated inside a database transaction.');
        }

        $department = Department::query()->lockForUpdate()->findOrFail($departmentId);
        $prefix = $department->code;
        $pattern = '/^'.preg_quote($prefix, '/').'-(\d+)$/';

        $highestSequence = Employee::query()
            ->withTrashed()
            ->where('employee_number', 'like', $prefix.'-%')
            ->pluck('employee_number')
            ->reduce(function (int $highest, string $employeeNumber) use ($pattern): int {
                if (! preg_match($pattern, $employeeNumber, $matches)) {
                    return $highest;
                }

                return max($highest, (int) $matches[1]);
            }, 0);

        return $prefix.'-'.str_pad((string) ($highestSequence + 1), 4, '0', STR_PAD_LEFT);
    }
}
