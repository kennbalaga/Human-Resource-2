<?php

namespace App\Http\Requests\Organization;

use App\Models\Department;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $roomType = $this->input('room_type');

        $this->merge([
            'code' => str($this->input('code'))->trim()->upper()->toString(),
            'name' => str($this->input('name'))->trim()->toString(),
            // A theatre and a delivery room start at charge level, because that
            // is the standard either way and a blank field would otherwise ship
            // a theatre that happily accepts an all-entry-level list.
            'min_seniority_rank' => $this->input('min_seniority_rank')
                ?: (in_array($roomType, Room::restrictedTypes(), true) ? Room::THEATRE_MINIMUM_RANK : 1),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $room = $this->route('room');

        // A room belongs to a unit that treats patients, and only to one. The
        // rule is enforced here rather than left to the picker, because the
        // picker is a convenience and this is the actual constraint: an
        // administrative unit has offices, not rooms anybody is rostered into.
        $departmentExists = Rule::exists('departments', 'id')
            ->where('category', Department::CATEGORY_CLINICAL);

        if ($room === null || $this->integer('department_id') !== $room->department_id) {
            $departmentExists->where('is_active', true);
        }

        return [
            'department_id' => ['required', 'integer', $departmentExists],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/', Rule::unique('rooms', 'code')->ignore($room?->id)],
            'name' => ['required', 'string', 'max:255'],
            'room_type' => ['required', Rule::in(array_keys(Room::types()))],
            'bed_capacity' => ['nullable', 'integer', 'between:1,500'],
            'max_staff' => ['nullable', 'integer', 'between:1,100'],
            'min_seniority_rank' => ['required', 'integer', 'between:1,5'],
            'status' => ['required', Rule::in(array_keys(Room::statuses()))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'A room code uses capitals, numbers and hyphens only — OR-1, WARD-A, OPD-C4.',
            'department_id.exists' => 'Rooms belong to clinical units only. Administrative and support units have no rooms to roster anybody into.',
        ];
    }
}
