<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesWorkforce;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\AttendanceRecord;
use App\Services\TimesheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends Controller
{
    use AuthorizesWorkforce;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireRead($request->user());
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $manager = $this->canManage($request->user());
        $records = AttendanceRecord::query()->with(['employee.user', 'employee.department', 'employee.position'])
            ->when(! $manager, fn (Builder $query) => $query->where('employee_id', $request->user()->employee?->id))
            ->when($manager && ! empty($validated['employee_id']), fn (Builder $query) => $query->where('employee_id', $validated['employee_id']))
            ->when($validated['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('attendance_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('attendance_date', '<=', $date))
            ->when($validated['approval_status'] ?? null, fn (Builder $query, $status) => $query->where('approval_status', $status))
            ->latest('attendance_date')->paginate($validated['per_page'] ?? 25);

        return AttendanceResource::collection($records);
    }

    public function show(Request $request, AttendanceRecord $attendanceRecord): AttendanceResource
    {
        $this->requireRead($request->user());
        abort_unless($this->canManage($request->user()) || $attendanceRecord->employee_id === $request->user()->employee?->id, 403);

        return new AttendanceResource($attendanceRecord->load(['employee.user', 'employee.department', 'employee.position']));
    }

    public function approve(Request $request, AttendanceRecord $attendanceRecord, TimesheetService $service): JsonResponse
    {
        $this->requireManager($request->user());
        $timesheet = $service->approveAttendance($attendanceRecord, $request->user());

        return response()->json(['message' => 'Attendance approved.', 'timesheet_id' => $timesheet->id]);
    }
}
