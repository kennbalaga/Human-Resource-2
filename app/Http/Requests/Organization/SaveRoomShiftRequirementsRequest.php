<?php

namespace App\Http\Requests\Organization;

use App\Models\Shift;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveRoomShiftRequirementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty() ?? false;
    }

    public function rules(): array
    {
        return [
            'requirements' => ['required', 'array', 'max:50'],
            'requirements.*.operates' => ['nullable', 'boolean'],
            'requirements.*.minimum_staff' => ['nullable', 'integer', 'between:1,100'],
            'requirements.*.minimum_senior' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Keyed by shift ID from the form, so confirmed against the shifts
            // table before any of it is written -- and against the shifts a
            // room can be staffed on at all, so a posted ID cannot write a
            // standard for the office day the form no longer offers.
            $shiftIds = array_keys((array) $this->input('requirements', []));
            $numericIds = array_filter($shiftIds, 'is_numeric');

            if (count($numericIds) !== count($shiftIds)
                || Shift::query()->staffsRooms()->whereKey($numericIds)->count() !== count($shiftIds)) {
                $validator->errors()->add('requirements', 'The room coverage form referenced a shift no room is staffed on.');
            }
        });
    }
}
