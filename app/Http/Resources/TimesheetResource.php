<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimesheetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'minutes' => [
                'regular' => $this->regular_minutes,
                'overtime' => $this->overtime_minutes,
                'late' => $this->late_minutes,
                'undertime' => $this->undertime_minutes,
                'total' => $this->total_minutes,
            ],
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewer_notes' => $this->reviewer_notes,
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn ($entry) => [
                'id' => $entry->id,
                'work_date' => $entry->work_date->toDateString(),
                'regular_minutes' => $entry->regular_minutes,
                'overtime_minutes' => $entry->overtime_minutes,
                'source' => $entry->source,
            ])),
        ];
    }
}
