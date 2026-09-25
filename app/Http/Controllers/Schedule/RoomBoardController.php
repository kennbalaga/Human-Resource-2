<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Room;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Services\Scheduling\RoomAssignmentService;
use App\Services\Scheduling\RoomRosterService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The room board: one unit, one day, every room against every shift.
 *
 * It only ever moves people between rooms. Putting somebody on duty, or taking
 * them off it, stays with the roster screens — see RoomAssignmentService for why
 * that line is drawn where it is.
 */
class RoomBoardController extends Controller
{
    use ScopesWorkforceAccess;

    public function __construct(
        private readonly RoomRosterService $rosters,
        private readonly RoomAssignmentService $assignments,
    ) {}

    public function index(Request $request): View
    {
        $this->requireBoardAccess($request);

        $filters = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'date' => ['nullable', 'date'],
        ]);

        $departments = $this->departmentsWithRooms($request);
        $department = $this->resolveDepartment($request, $departments, $filters['department_id'] ?? null);
        $date = $this->focusDate($filters['date'] ?? null);

        return view('schedules.rooms', [
            'departments' => $departments,
            'department' => $department,
            'date' => $date,
            'previousDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'isToday' => $date->isToday(),
            'canManage' => $this->canManage($request),
            'board' => $department === null ? null : $this->rosters->board($department, $date),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    /**
     * Everybody rostered on this shift and date, each with the reason they
     * cannot take this room, or null where they can.
     */
    public function candidates(Request $request, Room $room, Shift $shift): JsonResponse
    {
        $this->requireBoardAccess($request);
        $this->requireManager($request);

        // The board only has columns for the rotation's legs, so a request for
        // the office day did not come from it.
        abort_unless($shift->staffsRooms(), 404);

        $validated = $request->validate(['date' => ['required', 'date']]);

        $candidates = $this->assignments->candidates($room, $shift, $validated['date'])
            ->map(fn (array $row): array => [
                'employee_id' => $row['employee']->id,
                'name' => $row['employee']->full_name,
                'employee_number' => $row['employee']->employee_number,
                'position' => $row['employee']->position?->title,
                'rank' => (int) ($row['employee']->position?->seniority_rank ?? 1),
                'department' => $row['employee']->department?->name,
                'reason' => $row['reason'],
                'cross_unit' => $row['cross_unit'],
                'is_charge' => $row['is_charge'],
            ]);

        return response()->json([
            'room' => ['id' => $room->id, 'code' => $room->code, 'name' => $room->name],
            'shift' => ['id' => $shift->id, 'name' => $shift->name],
            'candidates' => $candidates->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->requireBoardAccess($request);
        $this->requireManager($request);

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
        ]);

        $room = Room::query()->findOrFail($validated['room_id']);
        $shift = Shift::query()->findOrFail($validated['shift_id']);
        $employee = Employee::query()->with('department')->findOrFail($validated['employee_id']);

        $this->requireSupervision($request, $employee, 'workforce.manage.record');

        $this->assignments->assign($room, $shift, $validated['date'], $employee, $request->user());

        // The board's Undo puts somebody back without leaving the page; it
        // redraws the board itself and says so in its own toast.
        if ($request->expectsJson()) {
            return response()->json(['message' => "{$employee->full_name} is back in {$room->code}."]);
        }

        return back()->with('success', "{$employee->full_name} is now in {$room->code}.");
    }

    public function destroy(Request $request, ScheduleAssignment $scheduleAssignment): RedirectResponse|JsonResponse
    {
        $this->requireBoardAccess($request);
        $this->requireManager($request);

        $employee = $scheduleAssignment->employee()->with('department')->first();
        $this->requireSupervision($request, $employee, 'workforce.manage.record');

        $room = $scheduleAssignment->room;
        $this->assignments->unassign($scheduleAssignment, $request->user());

        // Asked from the board's script: no prompt before the removal, so the
        // answer carries exactly what its Undo has to post back to store() to
        // put the same person in the same room again.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $room === null
                    ? 'That shift was not in a room.'
                    : "{$employee?->full_name} taken out of {$room->code}.",
                'undo' => $room === null ? null : [
                    'room_id' => $room->id,
                    'shift_id' => $scheduleAssignment->shift_id,
                    'employee_id' => $scheduleAssignment->employee_id,
                    'date' => $scheduleAssignment->work_date->toDateString(),
                ],
            ]);
        }

        return back()->with('success', $room === null
            ? 'That shift was not in a room.'
            : "{$employee?->full_name} is out of {$room->code}.");
    }

    /**
     * Units this account may look at that actually have rooms.
     *
     * Two narrowings, for two different reasons. Clinical, because the room
     * board is a clinical instrument -- Finance has offices, not wards. And
     * only units that have rooms, because a unit with none has no board, and
     * offering it produces an empty screen the user has to back out of.
     *
     * @return Collection<int, Department>
     */
    private function departmentsWithRooms(Request $request)
    {
        $departmentIds = Room::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('department_id');

        return $this->selectableDepartments($request)
            ->where('category', Department::CATEGORY_CLINICAL)
            ->whereIn('id', $departmentIds->all())
            ->values();
    }

    /**
     * @param  Collection<int, Department>  $departments
     */
    private function resolveDepartment(Request $request, $departments, ?int $requested): ?Department
    {
        if ($requested !== null) {
            $chosen = $departments->firstWhere('id', $requested);

            abort_if($chosen === null, 403, 'The room board covers clinical units you supervise that have rooms recorded. This one is not among them.');

            return $chosen;
        }

        // A charge nurse lands on their own ward rather than on whichever unit
        // sorts first alphabetically.
        $own = $request->user()->employee?->department_id;

        return $departments->firstWhere('id', $own) ?? $departments->first();
    }

    private function requireBoardAccess(Request $request): void
    {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);
    }

    private function requireManager(Request $request): void
    {
        abort_unless(
            Gate::forUser($request->user())->allows('workforce.manage') && $request->user()->canManageData(),
            403,
        );
    }

    private function canManage(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('workforce.manage') && $request->user()->canManageData();
    }

    private function focusDate(?string $date): Carbon
    {
        try {
            return $date
                ? Carbon::parse($date, config('schedule.timezone'))->startOfDay()
                : now(config('schedule.timezone'))->startOfDay();
        } catch (\Throwable) {
            return now(config('schedule.timezone'))->startOfDay();
        }
    }
}
