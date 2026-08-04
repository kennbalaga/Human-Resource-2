<?php

namespace App\Http\Requests\Organization;

use App\Models\Shift;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveShiftRequirementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty() ?? false;
    }

    public function rules(): array
    {
        return [
            'requirements' => ['required', 'array', 'max:50'],
            'requirements.*.minimum_staff' => ['nullable', 'integer', 'between:1,100'],
            'requirements.*.minimum_senior' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // The array is keyed by shift ID, which arrives from the form and is
            // therefore confirmed against the shifts table before it is written.
            $shiftIds = array_keys((array) $this->input('requirements', []));
            $numericIds = array_filter($shiftIds, 'is_numeric');

            if (count($numericIds) !== count($shiftIds)
                || Shift::query()->whereKey($numericIds)->count() !== count($shiftIds)) {
                $validator->errors()->add('requirements', 'The shift coverage form referenced an unknown shift.');
            }
        });
    }
}
