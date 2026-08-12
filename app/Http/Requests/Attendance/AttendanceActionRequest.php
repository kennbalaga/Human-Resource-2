<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'office_location_id' => ['required', 'integer', 'exists:office_locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Named destinations only. The staff dashboard clocks out in place and
            // has to come back to itself; accepting a URL here would turn a form
            // field into an open redirect.
            'return_to' => ['nullable', 'in:dashboard'],
        ];
    }
}
