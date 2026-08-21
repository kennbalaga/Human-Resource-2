<?php

namespace App\Services\Scheduling;

use App\Models\ScheduleAssignment;
use Carbon\Carbon;

/**
 * The outcome of resolving a punch against the published roster. Pure data —
 * {@see ShiftResolver} never writes anything; only AttendanceService does.
 */
final class ShiftResolution
{
    public const ON_SHIFT = 'on_shift';

    public const EARLY = 'early';

    public const LATE = 'late';

    public const OFF_SHIFT = 'off_shift';

    public const UNSCHEDULED = 'unscheduled';

    public const REASON_NO_CANDIDATES = 'no_candidates';

    public const REASON_LEAVE_CONFLICT = 'leave_conflict';

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $matchedWindow
     */
    public function __construct(
        public readonly ?ScheduleAssignment $assignment,
        public readonly string $scheduleStatus,
        public readonly ?array $matchedWindow = null,
        public readonly ?string $reason = null,
    ) {}

    public function isBound(): bool
    {
        return $this->assignment !== null;
    }

    public function start(): ?Carbon
    {
        return $this->matchedWindow[0] ?? null;
    }

    public function end(): ?Carbon
    {
        return $this->matchedWindow[1] ?? null;
    }
}
