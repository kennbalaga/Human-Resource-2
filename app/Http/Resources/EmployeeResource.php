<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'name' => $this->full_name,
            'email' => $this->user?->email,
            'department' => $this->department ? ['id' => $this->department->id, 'code' => $this->department->code, 'name' => $this->department->name] : null,
            'position' => $this->position ? ['id' => $this->position->id, 'code' => $this->position->code, 'name' => $this->position->name] : null,
            'employment_status' => $this->employment_status,
            'hire_date' => $this->hire_date?->toDateString(),
        ];
    }
}
