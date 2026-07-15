<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'leave_type' => $this->leaveType ? ['id' => $this->leaveType->id, 'code' => $this->leaveType->code, 'name' => $this->leaveType->name] : null,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'requested_days' => (float) $this->requested_days,
            'reason' => $this->reason,
            'status' => $this->status,
            'reviewer_notes' => $this->reviewer_notes,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ])),
        ];
    }
}
