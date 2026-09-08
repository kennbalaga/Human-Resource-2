<?php

namespace App\Reports;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * The reports the Insights module offers, in the order the hub lists them.
 *
 * Route binding, the hub, the export guard and the read-only middleware all
 * ask this class rather than hard-coding a list, so adding a report is one
 * entry here plus its definition -- not an edit in five files, which is how
 * the module ended up with exactly one report for as long as it did.
 */
final class ReportRegistry
{
    /** @var array<string, class-string<Report>> */
    public const REPORTS = [
        'attendance' => AttendanceReport::class,
        'leave' => LeaveReport::class,
        'timesheet' => TimesheetReport::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return Collection<int, Report>
     */
    public function all(): Collection
    {
        return collect(self::REPORTS)->map(fn (string $class) => $this->container->make($class))->values();
    }

    public function find(?string $key): ?Report
    {
        $class = self::REPORTS[$key] ?? null;

        return $class === null ? null : $this->container->make($class);
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::REPORTS);
    }
}
