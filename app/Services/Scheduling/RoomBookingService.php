<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Theatre lists: holding a room for a case, and scrubbing a rostered team to it.
 *
 * The overlap rule below is the whole reason this exists apart from
 * RoomAssignmentService. A room's occupancy by *shift* is a matter of counting
 * heads, and `schedule_assignments` already answers it. A room's occupancy by
 * *clock* is a matter of intervals, and two cases in one theatre on one morning
 * must not be allowed to share one.
 *
 * What this deliberately does not do is give anybody a second shift. Attaching a
 * person to a case sets `room_booking_id` on the duty they were already
 * rostered, exactly as room placement sets `room_id` -- so the unique key on
 * (employee, shift, work_date) still holds, and one person cannot be scrubbed
 * for two overlapping cases inside the same shift.
 */
class RoomBookingService
{
    public function __construct(
        private readonly RoomAssignmentService $roomAssignments,
        private readonly ScheduleLockService $locks,
    ) {}

    /**
     * Hold a room for a case.
     *
     * @param  array{work_date: string, start_time: string, end_time: string, purpose: string, shift_id?: int|null, lead_employee_id?: int|null, notes?: string|null, status?: string}  $data
     *
     * @throws ValidationException
     */
    public function book(Room $room, array $data, User $creator): RoomBooking
    {
        return DB::transaction(function () use ($room, $data, $creator): RoomBooking {
            $date = $this->asDate($data['work_date']);

            if (! $room->isUsable()) {
                throw ValidationException::withMessages([
                    'room_id' => "{$room->code} is {$room->status_label}. It cannot be booked until it reopens.",
                ]);
            }

            if ($date->lessThan(now(config('schedule.timezone'))->startOfDay())) {
                throw ValidationException::withMessages([
                    'work_date' => 'A room cannot be booked for a date that has already passed.',
                ]);
            }

            [$start, $end] = $this->window($data['start_time'], $data['end_time']);

            $this->assertRoomFree($room, $date, $start, $end);

            return RoomBooking::query()->create([
                'room_id' => $room->id,
                'shift_id' => $data['shift_id'] ?? null,
                'work_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'purpose' => $data['purpose'],
                'lead_employee_id' => $data['lead_employee_id'] ?? null,
                'status' => $data['status'] ?? RoomBooking::STATUS_PLANNED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);
        });
    }

    /**
     * Stand a case down. The room is free again the moment this returns, and
     * anybody scrubbed to it keeps their duty — they are simply no longer on
     * this case.
     */
    public function cancel(RoomBooking $booking, User $actor, ?string $reason = null): RoomBooking
    {
        return DB::transaction(function () use ($booking, $actor, $reason): RoomBooking {
            $booking = RoomBooking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isHoldingTheRoom()) {
                throw ValidationException::withMessages([
                    'status' => 'This case is already '.strtolower(RoomBooking::statuses()[$booking->status] ?? $booking->status).'.',
                ]);
            }

            RosterWriteContext::allow($actor, function () use ($booking): void {
                $booking->scheduleAssignments()->get()
                    ->each(fn (ScheduleAssignment $assignment) => $assignment->update(['room_booking_id' => null]));
            });

            $booking->update([
                'status' => RoomBooking::STATUS_CANCELLED,
                'notes' => trim(collect([$booking->notes, $reason])->filter()->implode(' | ')) ?: null,
            ]);

            return $booking->refresh();
        });
    }

    /**
     * Scrub a rostered person to a case.
     *
     * The room placement comes with it: somebody on a case is, by definition, in
     * the room the case is in, and leaving the two to be set separately is how
     * they end up disagreeing.
     *
     * @throws ValidationException
     */
    public function attach(RoomBooking $booking, Employee $employee, User $actor): ScheduleAssignment
    {
        if (! $booking->isHoldingTheRoom()) {
            throw ValidationException::withMessages([
                'room_booking_id' => 'This case is no longer scheduled, so nobody can be scrubbed to it.',
            ]);
        }

        $room = $booking->room ?? Room::query()->findOrFail($booking->room_id);
        $shift = $this->shiftFor($booking, $employee);

        if ($shift === null) {
            throw ValidationException::withMessages([
                'employee_id' => "{$employee->full_name} is not rostered on ".$booking->work_date->format('M j, Y').', so there is no duty to scrub to this case.',
            ]);
        }

        if ($employee->department !== null) {
            $this->locks->assertUnlocked($employee->department, $this->asDate($booking->work_date));
        }

        $assignment = $this->roomAssignments->assign($room, $shift, $booking->work_date, $employee, $actor);

        return RosterWriteContext::allow($actor, function () use ($assignment, $booking) {
            $assignment->update(['room_booking_id' => $booking->id]);

            return $assignment->refresh();
        });
    }

    /**
     * The day's list for a room, in the order it runs.
     *
     * @return Collection<int, RoomBooking>
     */
    public function listFor(Room $room, Carbon|string $workDate): Collection
    {
        return RoomBooking::query()
            ->with(['lead', 'shift'])
            ->where('room_id', $room->id)
            ->whereDate('work_date', $this->asDate($workDate)->toDateString())
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Every holding booking for a unit on one day, keyed by room — one query for
     * the whole board rather than one per theatre.
     *
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, Collection<int, RoomBooking>>
     */
    public function listForRooms(Collection $rooms, Carbon|string $workDate): Collection
    {
        if ($rooms->isEmpty()) {
            return collect();
        }

        return RoomBooking::query()
            ->with('lead')
            ->whereIn('room_id', $rooms->pluck('id')->all())
            ->whereDate('work_date', $this->asDate($workDate)->toDateString())
            ->holding()
            ->orderBy('start_time')
            ->get()
            ->groupBy('room_id');
    }

    /**
     * Two cases may not hold one room over the same minute.
     *
     * Touching ends are fine and common: a list that runs to 12:00 hands over to
     * one that starts at 12:00. Hence the strict comparisons — an overlap needs
     * a shared minute, not a shared boundary.
     *
     * @throws ValidationException
     */
    private function assertRoomFree(Room $room, Carbon $date, string $start, string $end, ?int $ignoreId = null): void
    {
        $clash = RoomBooking::query()
            ->where('room_id', $room->id)
            ->whereDate('work_date', $date->toDateString())
            ->holding()
            ->when($ignoreId, fn ($query, $id) => $query->whereKeyNot($id))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'start_time' => "{$room->code} is already held from {$clash->window} for {$clash->purpose}.",
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws ValidationException
     */
    private function window(string $start, string $end): array
    {
        $startTime = $this->asTime($start);
        $endTime = $this->asTime($end);

        // A list that ends before it starts is a typo, not an overnight case.
        // Theatre lists that genuinely run past midnight are booked as two, one
        // on each date, because that is how the room is actually held.
        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => 'A case must end after it starts. A list running past midnight is booked as one case on each date.',
            ]);
        }

        return [$startTime, $endTime];
    }

    /**
     * The duty this person is already rostered for on the day of the case.
     *
     * Preferring the booking's own shift keeps a case tied to the list it was
     * planned against; without one, any duty that day will do, because a theatre
     * list timed by the clock need not line up with a shift pattern.
     */
    private function shiftFor(RoomBooking $booking, Employee $employee): ?Shift
    {
        $assignment = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $this->asDate($booking->work_date)->toDateString())
            ->where('status', 'scheduled')
            ->when($booking->shift_id, fn ($query, $shiftId) => $query->orderByRaw('case when shift_id = ? then 0 else 1 end', [$shiftId]))
            ->first();

        return $assignment?->shift;
    }

    private function asTime(string $value): string
    {
        return Carbon::parse($value, config('schedule.timezone'))->format('H:i:s');
    }

    private function asDate(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon
            ? $date->copy()->timezone(config('schedule.timezone'))->startOfDay()
            : Carbon::parse($date, config('schedule.timezone'))->startOfDay();
    }
}
