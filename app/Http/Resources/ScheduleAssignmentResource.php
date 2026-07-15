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
            'status' => $this->status,
            'recurring' => $this->recurring_schedule_id !== null,
            'notes' => $this->notes,
        ];
    }
}
