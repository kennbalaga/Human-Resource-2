<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * A semi-monthly pay period: the 1st to the 15th, or the 16th to month end.
 *
 * Timesheets stay weekly, because that is how attendance is reviewed. Payroll
 * in the Philippines pays twice a month, though, so a payslip gathers whichever
 * approved days fall inside its half of the month, and a week that straddles
 * the 15th simply contributes to both.
 */
final class PayslipPeriod
{
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function forDate(DateTimeInterface|string $date): self
    {
        $day = ($date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date))->startOfDay();

        return $day->day <= 15
            ? new self($day->copy()->startOfMonth(), $day->copy()->setDay(15))
            : new self($day->copy()->setDay(16), $day->copy()->endOfMonth()->startOfDay());
    }

    /**
     * The URL form: `2026-08-1` for the first half of August, `2026-08-2` for
     * the second. Null for anything else, so a hand-typed URL is a 404.
     */
    public static function fromKey(string $key): ?self
    {
        if (! preg_match('/^(\d{4})-(\d{2})-([12])$/', $key, $parts) || ! checkdate((int) $parts[2], 1, (int) $parts[1])) {
            return null;
        }

        return self::forDate(sprintf('%s-%s-%s', $parts[1], $parts[2], $parts[3] === '1' ? '01' : '16'));
    }

    public function key(): string
    {
        return $this->start->format('Y-m').'-'.($this->isFirstHalf() ? '1' : '2');
    }

    public function label(): string
    {
        return $this->start->format('M j').'–'.$this->end->format('j, Y');
    }

    /**
     * Derived rather than stored: the same employee and period always carry the
     * same number, so a reprinted payslip matches the one handed out before.
     */
    public function number(Employee $employee): string
    {
        return sprintf('PS-%s%s-%05d', $this->start->format('Ym'), $this->isFirstHalf() ? 'A' : 'B', $employee->id);
    }

    /**
     * Bounds for a DATE column: a bare date below, the last second of the
     * period above, for the serialisation reason Report::rangeStart() explains.
     *
     * @return array{0: string, 1: string}
     */
    public function range(): array
    {
        return [$this->start->toDateString(), $this->end->copy()->endOfDay()->toDateTimeString()];
    }

    private function isFirstHalf(): bool
    {
        return $this->start->day === 1;
    }
}
