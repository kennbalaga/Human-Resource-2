<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Someone you are on shift with.
 *
 * Reached by tapping a name on the schedule's day card, and reachable no other
 * way: there is no directory search on the phone, and the only names an
 * employee can open are the ones they are already rostered beside.
 *
 * The access rule here is the link's rule, enforced again rather than assumed.
 * A URL is a guess away, so "you share this shift" has to be checked on the
 * server or the page becomes the staff directory the phone deliberately does
 * not have — for clinical staff, in their own department, on a date they are
 * actually rostered together.
 */
class ColleagueController extends Controller
{
    public function __invoke(Request $request, Employee $employee): View
    {
        $me = $request->user()->employee;
        abort_if($me === null, 403, 'Your user account is not linked to an employee profile.');
        abort_if($me->is($employee), 404);

        /*
         * The same three conditions the chip is drawn under. Clinical staff,
         * because that is where the swap page already draws the line and this
         * is not the place to move it; same department, because that is the
         * scope those rosters were ever visible in.
         */
        abort_unless($me->canUseShiftSwaps(), 403, 'Shift colleagues are shown to clinical staff.');
        abort_unless($me->department_id !== null && $me->department_id === $employee->department_id, 403);

        $validated = $request->validate(['date' => ['nullable', 'date']]);
        $timezone = config('schedule.timezone');
        $date = Carbon::parse($validated['date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();

        $mine = $this->assignmentOn($me, $date);
        abort_if($mine === null, 404, 'You are not rostered on that day.');

        $theirs = ScheduleAssignment::query()
            ->with(['shift', 'room'])
            ->where('employee_id', $employee->id)
            ->where('work_date', $date->toDateString())
            ->where('shift_id', $mine->shift_id)
            ->where('status', 'scheduled')
            ->first();

        // Not rostered together is a 404 rather than a 403: there is nothing
        // here to be refused, and saying "forbidden" would confirm something
        // about a roster the reader is not entitled to ask about.
        abort_if($theirs === null, 404, 'You are not on this shift together.');

        $employee->loadMissing(['position', 'department']);

        return view('colleagues.show', [
            'colleague' => $employee,
            'date' => $date,
            'isToday' => $date->isSameDay(Carbon::now($timezone)->startOfDay()),
            'isPast' => $date->lt(Carbon::now($timezone)->startOfDay()),
            'shift' => $theirs->shift,
            'hours' => $theirs->shift !== null
                ? Carbon::parse($theirs->shift->start_time)->format('g:i A').' – '.Carbon::parse($theirs->shift->end_time)->format('g:i A')
                : null,
            'theirRoom' => $theirs->room?->name,
            'myRoom' => $mine->room?->name,
            'sameRoom' => $theirs->room_id !== null && $theirs->room_id === $mine->room_id,
        ]);
    }

    private function assignmentOn(Employee $employee, Carbon $date): ?ScheduleAssignment
    {
        return ScheduleAssignment::query()
            ->with('room')
            ->where('employee_id', $employee->id)
            ->where('work_date', $date->toDateString())
            ->where('status', 'scheduled')
            ->first();
    }
}
