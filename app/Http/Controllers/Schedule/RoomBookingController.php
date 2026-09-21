<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Services\Scheduling\RoomBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Theatre lists — holding a room for a case, standing one down, and scrubbing a
 * rostered person to it. Booked from the room board, where the day is in view.
 */
class RoomBookingController extends Controller
{
    public function __construct(private readonly RoomBookingService $bookings) {}

    public function store(Request $request, Room $room): RedirectResponse
    {
        $this->requireManager($request);

        $validated = $request->validate([
            'work_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'purpose' => ['required', 'string', 'max:255'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'lead_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in([RoomBooking::STATUS_PLANNED, RoomBooking::STATUS_CONFIRMED])],
        ]);

        $booking = $this->bookings->book($room, $validated, $request->user());

        return back()->with('success', "{$room->code} is held {$booking->window} for {$booking->purpose}.");
    }

    public function attach(Request $request, RoomBooking $roomBooking): RedirectResponse
    {
        $this->requireManager($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        $employee = Employee::query()->with('department')->findOrFail($validated['employee_id']);

        $this->bookings->attach($roomBooking, $employee, $request->user());

        return back()->with('success', "{$employee->full_name} is scrubbed to {$roomBooking->purpose}.");
    }

    public function destroy(Request $request, RoomBooking $roomBooking): RedirectResponse
    {
        $this->requireManager($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->bookings->cancel($roomBooking, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', "{$roomBooking->purpose} stood down. The room is free again.");
    }

    private function requireManager(Request $request): void
    {
        abort_unless(
            Gate::forUser($request->user())->allows('workforce.manage') && $request->user()->canManageData(),
            403,
        );
    }
}
