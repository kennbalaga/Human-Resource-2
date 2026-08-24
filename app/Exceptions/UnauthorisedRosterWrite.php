<?php

namespace App\Exceptions;

use App\Services\Scheduling\RosterWriteContext;
use RuntimeException;

/**
 * Thrown when something tries to create, update, or delete a ScheduleAssignment
 * outside {@see RosterWriteContext::allow()}. Production
 * should never see this — it means a write path exists that nobody accounted for.
 */
class UnauthorisedRosterWrite extends RuntimeException
{
    //
}
