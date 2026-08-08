<?php

namespace App\Http\Requests\ShiftSwap;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'requester_assignment_id' => ['required', 'integer', 'exists:schedule_assignments,id'],
            'target_assignment_id' => ['required', 'integer', 'exists:schedule_assignments,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
