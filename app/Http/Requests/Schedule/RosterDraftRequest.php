<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\Concerns\ScopesToSupervisedDepartments;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A roster exactly as the nursing office left it on screen.
 */
class RosterDraftRequest extends FormRequest
{
    use ScopesToSupervisedDepartments;

    protected function prepareForValidation(): void
    {
        $holidayDates = collect(preg_split('/[\s,]+/', (string) $this->input('holiday_dates_csv')))
            ->filter()
            ->values()
            ->all();
        $this->merge(['holiday_dates' => $holidayDates]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('workforce.view');
    }

    public function rules(): array
    {
        // Built conditionally rather than via Rule::requiredIf() alongside
        // 'nullable': the two fight each other. ConvertEmptyStringsToNull
        // turns an empty field into null before validation runs, so without
        // 'nullable' a blank, not-required justification fails the 'string'
        // check — but 'nullable' combined with requiredIf silently skips the
        // required check too, once the value is null. Branching up front
        // avoids relying on how those two interact.
        $overtimeAllowed = $this->boolean('overtime_allowed');

        return [
            'department_id' => ['required', 'integer', 'exists:departments,id', ...$this->supervisedDepartmentRules()],
            'position_ids' => ['nullable', 'array'],
            'position_ids.*' => ['integer', 'distinct', Rule::exists('positions', 'id')->where('department_id', $this->input('department_id'))],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'draft_uuid' => ['nullable', 'uuid', 'exists:roster_drafts,uuid'],

            'entries' => ['present', 'array', 'max:'.config('schedule.max_roster_entries', 2000)],
            'entries.*.employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where('department_id', $this->input('department_id')),
            ],
            // Null marks a rest day, which is a deliberate roster decision rather
            // than the absence of one.
            'entries.*.shift_id' => ['present', 'nullable', 'integer', 'exists:shifts,id'],
            'entries.*.work_date' => ['required', 'date'],

            // Which shift(s) this run is actually about — a single shift for
            // a fixed pattern, several for a rotation/custom pool — so the
            // board and its coverage gate judge only the shifts this roster
            // was built for, not every active shift in the department.
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'shift_ids' => ['nullable', 'array'],
            'shift_ids.*' => ['integer', 'exists:shifts,id'],

            // The same Step 2 rules the roster was generated under, so a live
            // recompute or the final publish check holds it to the same policy.
            'days_off_per_week' => ['nullable', 'integer', 'between:0,6'],
            'max_hours_per_week' => ['nullable', 'integer', 'between:1,168'],
            'night_shift_limit' => ['nullable', 'integer', 'between:0,7'],
            'max_consecutive_nights' => ['nullable', 'integer', 'between:1,7'],
            'minimum_rest_hours' => ['nullable', 'integer', 'between:1,48'],
            'overtime_allowed' => ['nullable', 'boolean'],
            'overtime_justification' => $overtimeAllowed
                ? ['required', 'string', 'max:500']
                : ['nullable', 'string', 'max:500'],
            // Only actually required at publish time, once the computed
            // evaluation shows a consecutive-night streak beyond the limit —
            // RosterDraftService checks that, since only it knows whether
            // this roster has one.
            'night_streak_justification' => ['nullable', 'string', 'max:500'],
            'maximum_staff_per_shift' => ['nullable', 'integer', 'between:1,100'],
            'minimum_senior_per_shift' => ['nullable', 'integer', 'between:0,100'],
            'senior_rank_threshold' => ['nullable', 'integer', 'between:2,'.Position::MAX_SENIORITY_RANK],
            'holiday_dates_csv' => ['nullable', 'string', 'max:500'],
            'holiday_dates' => ['nullable', 'array', 'max:31'],
            'holiday_dates.*' => ['date', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'entries.*.employee_id.exists' => 'A rostered employee does not belong to the selected department.',
            'overtime_justification.required' => 'Explain why overtime is being allowed for this run.',
        ];
    }
}
