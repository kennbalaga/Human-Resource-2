<?php

namespace App\Services\Scheduling;

use App\Exceptions\UnauthorisedRosterWrite;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The write boundary for `schedule_assignments`. Every create/update/delete
 * must happen inside {@see allow()} (or, for console/seeder contexts with no
 * authenticated user, {@see allowUnattended()}), or ScheduleAssignment's model
 * events throw. This guarantees a human is accountable for every roster write
 * without re-deciding *which* human is allowed to do *what* — that permission
 * check already happens at each call site (Gates, FormRequest::authorize());
 * duplicating it here would risk disagreeing with the real check.
 */
final class RosterWriteContext
{
    private static int $depth = 0;

    private static bool $unattended = false;

    /** @var array<int, User> */
    private static array $actorStack = [];

    public static function allow(User $actor, callable $write): mixed
    {
        if (! $actor->exists) {
            throw new RuntimeException('RosterWriteContext::allow() requires a persisted User.');
        }

        self::$depth++;
        self::$actorStack[] = $actor;

        try {
            return $write();
        } finally {
            self::$depth--;
            array_pop(self::$actorStack);
        }
    }

    /**
     * The console/seeder escape hatch — no authenticated user exists in that
     * context. Refuses to open outside local/testing, so a stray unattended
     * write path can never reach production data unnoticed.
     */
    public static function allowUnattended(callable $write): mixed
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('RosterWriteContext::allowUnattended() is not available outside local/testing.');
        }

        self::$depth++;
        self::$unattended = true;

        try {
            return $write();
        } finally {
            self::$depth--;
            if (self::$depth === 0) {
                self::$unattended = false;
            }
        }
    }

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    public static function currentActor(): ?User
    {
        return self::$actorStack === [] ? null : self::$actorStack[count(self::$actorStack) - 1];
    }

    public static function isUnattended(): bool
    {
        return self::$unattended;
    }

    public static function denyWrite(string $event): never
    {
        Log::warning('roster.unauthorised_write', [
            'event' => $event,
            'unattended' => self::$unattended,
            'trace' => (new RuntimeException)->getTraceAsString(),
        ]);

        throw new UnauthorisedRosterWrite(
            "ScheduleAssignment {$event} attempted outside RosterWriteContext::allow().",
        );
    }
}
