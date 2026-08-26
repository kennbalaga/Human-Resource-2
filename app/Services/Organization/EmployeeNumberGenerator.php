<?php

namespace App\Services\Organization;

use App\Models\Employee;
use App\Models\Position;
use Carbon\Carbon;
use LogicException;

/**
 * Every employee ID reads {POSITION CODE}-{HIRE YEAR}-{SEQUENCE}, for example
 * NUR-HEAD-OPD-2026-0009. Positions are scoped to a single department, so the
 * position code already carries the department (OPD here) and the ID stays
 * readable as "Head Nurse, Outpatient Department, hired 2026, ninth of that
 * intake". The sequence runs per position and hire year.
 */
class EmployeeNumberGenerator
{
    private const SEQUENCE_LENGTH = 4;

    public function generateForPosition(int $positionId, string $hireDate): string
    {
        if (! Employee::query()->getConnection()->transactionLevel()) {
            throw new LogicException('Employee numbers must be generated inside a database transaction.');
        }

        // Locking the position rather than the department: it is the position
        // that owns the sequence, so two hires into different positions of the
        // same department no longer wait on each other.
        $position = Position::query()->lockForUpdate()->findOrFail($positionId);
        $hireYear = Carbon::parse($hireDate)->year;

        return self::compose($position->code, $hireYear, self::nextSequence($position->code, $hireYear));
    }

    /**
     * The next free sequence for a position and hire year. Retired employees
     * are counted so a number is never handed out twice.
     */
    public static function nextSequence(string $positionCode, int $hireYear): int
    {
        $pattern = self::pattern($positionCode, $hireYear);

        return Employee::query()
            ->withTrashed()
            ->where('employee_number', 'like', $positionCode.'-'.$hireYear.'-%')
            ->pluck('employee_number')
            ->reduce(fn (int $highest, string $employeeNumber): int => preg_match($pattern, $employeeNumber, $matches)
                ? max($highest, (int) $matches[1])
                : $highest, 0) + 1;
    }

    public static function compose(string $positionCode, int $hireYear, int $sequence): string
    {
        return $positionCode.'-'.$hireYear.'-'.str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }

    /** Whether an ID already reads as this position's ID for this hire year. */
    public static function follows(string $employeeNumber, string $positionCode, int $hireYear): bool
    {
        return preg_match(self::pattern($positionCode, $hireYear), $employeeNumber) === 1;
    }

    private static function pattern(string $positionCode, int $hireYear): string
    {
        return '/^'.preg_quote($positionCode.'-'.$hireYear.'-', '/').'(\d{'.self::SEQUENCE_LENGTH.'})$/';
    }
}
