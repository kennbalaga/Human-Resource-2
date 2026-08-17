<?php

namespace App\Http\Requests\ShiftSwap;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Requesting a swap is self-service, not the manager review that HR
        // Manager, System Administrator, and Department Head keep regardless of
        // their own department (see ShiftSwapController::index). An
        // administrative employee, manager or not, has no rotating shift to
        // trade, so this check is the same for everyone here.
        return $this->user()?->employee?->canUseShiftSwaps() ?? false;
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
