<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'work_date' => $this->work_date->toDateString(),
            'shift' => $this->shift ? [
                'id' => $this->shift->id,
                'code' => $this->shift->code,
                'name' => $this->shift->name,
                'start_time' => $this->shift->start_time,
                'end_time' => $this->shift->end_time,
                'crosses_midnight' => $this->shift->crosses_midnight,
            ] : null,
            // Null for every shift worked nowhere in particular -- administrative
            // duty, on-call, a float nurse. That is the common case, not an
            // omission.
            'room' => $this->room ? [
                'id' => $this->room->id,
                'code' => $this->room->code,
                'name' => $this->room->name,
                'type' => $this->room->room_type,
                'department_id' => $this->room->department_id,
            ] : null,
            'borrowed_from_another_unit' => (bool) $this->cross_unit,
            'status' => $this->status,
            'recurring' => $this->recurring_schedule_id !== null,
            'notes' => $this->notes,
        ];
    }
}
