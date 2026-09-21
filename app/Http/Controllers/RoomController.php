<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\SaveRoomRequest;
use App\Http\Requests\Organization\SaveRoomShiftRequirementsRequest;
use App\Models\Department;
use App\Models\Room;
use App\Models\Shift;
use App\Services\Scheduling\StaffingRequirementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'room_type' => ['nullable', 'in:'.implode(',', array_keys(Room::types()))],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Room::statuses()))],
        ]);

        $rooms = Room::query()
            ->with('department')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['room_type'] ?? null, fn (Builder $query, string $type) => $query->where('room_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('department_id')
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('rooms.index', [
            'rooms' => $rooms,
            'departments' => Department::query()->clinical()->where('is_active', true)->orderBy('name')->get(),
            'types' => Room::types(),
            'statuses' => Room::statuses(),
            'filters' => $filters,
            'canManage' => $this->canWrite($request),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function create(Request $request): View
    {
        $this->requireManager($request);

        return view('rooms.create', $this->formData($request));
    }

    public function store(SaveRoomRequest $request): RedirectResponse
    {
        $room = Room::query()->create($request->validated());

        return redirect()->route('rooms.edit', $room)->with('success', 'Room created successfully.');
    }

    public function edit(Request $request, Room $room): View
    {
        $this->requireManager($request);

        $shifts = Shift::query()->where('is_active', true)->orderBy('start_time')->get();

        return view('rooms.edit', $this->formData($request, $room) + [
            'room' => $room,
            'shifts' => $shifts,
            'requirements' => $room->shiftRequirements()->get()->keyBy('shift_id'),
            'derivedMinimum' => $room->derivedMinimumStaffPerShift(),
            'derivationSummary' => app(StaffingRequirementService::class)->roomDerivationSummary($room),
        ]);
    }

    public function update(SaveRoomRequest $request, Room $room): RedirectResponse
    {
        $room->update($request->validated());

        return back()->with('success', 'Room updated successfully.');
    }

    /**
     * Record what each shift of this room must be staffed to, and which shifts
     * it runs at all. Saved apart from the room details so a coverage change
     * reads as its own decision, the way a department's does.
     */
    public function updateShiftRequirements(SaveRoomShiftRequirementsRequest $request, Room $room): RedirectResponse
    {
        foreach ($request->validated()['requirements'] as $shiftId => $requirement) {
            $minimumStaff = $requirement['minimum_staff'] ?? null;

            $room->shiftRequirements()->updateOrCreate(
                ['shift_id' => $shiftId],
                [
                    // Absent means the checkbox was cleared, which is exactly
                    // how a shift gets marked dark.
                    'operates' => (bool) ($requirement['operates'] ?? false),
                    'minimum_staff' => $minimumStaff === '' ? null : $minimumStaff,
                    'minimum_senior' => (int) ($requirement['minimum_senior'] ?? 0),
                ],
            );
        }

        return back()->with('success', 'Room coverage standard updated.');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Room $room = null): array
    {
        return [
            // Clinical units only. A room already attached to some other unit
            // stays selectable so an existing row can still be saved, rather
            // than the form silently dropping the value it was opened with.
            'departments' => Department::query()
                ->where(fn (Builder $query) => $query->clinical()->where('is_active', true)->when($room, fn (Builder $nested) => $nested->orWhere('id', $room->department_id)))
                ->orderBy('name')
                ->get(),
            'types' => Room::types(),
            'statuses' => Room::statuses(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ];
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty();
    }

    private function canWrite(Request $request): bool
    {
        return $this->canManage($request) && $request->user()->canManageData();
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canWrite($request), 403);
    }
}
