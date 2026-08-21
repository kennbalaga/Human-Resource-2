<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AttendanceOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('attendance.override');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'office_location_id' => ['required', 'integer', 'exists:office_locations,id'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
