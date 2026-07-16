<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'attendance_date' => $this->attendance_date->toDateString(),
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'check_out_at' => $this->check_out_at?->toIso8601String(),
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'minutes' => [
                'worked' => $this->worked_minutes,
                'late' => $this->late_minutes,
                'undertime' => $this->undertime_minutes,
                'overtime' => $this->overtime_minutes,
            ],
        ];
    }
}
