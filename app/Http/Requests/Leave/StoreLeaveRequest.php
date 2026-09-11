<?php

namespace App\Http\Requests\Leave;

use App\Models\LeaveType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    /**
     * HR managers review leave rather than filing their own. System
     * administrators are staff like anyone else, so their own leave request is
     * self-service and stays available even though they cannot approve one.
     *
     * @var array<int, string>
     */
    public const ROLES_WITHOUT_SELF_SERVICE = ['hr-manager'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->employee !== null && ! $user->hasAnyRole(self::ROLES_WITHOUT_SELF_SERVICE);
    }

    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['bail', 'required', 'date'],
            'end_date' => [
                'bail',
                'required',
                'date',
                'after_or_equal:start_date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->date('start_date') || ! $this->date('end_date')) {
                        return;
                    }

                    $cap = $this->maximumSpan();

                    if ($this->date('start_date')->diffInDays($this->date('end_date')) > $cap) {
                        $fail('A leave request may not exceed '.$cap.' calendar days.');
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('workforce.attachment_max_kilobytes')],
        ];
    }

    /**
     * The configured cap covers ordinary leave. A statutory entitlement longer
     * than it raises the ceiling to its own: maternity leave runs to 105
     * calendar days, and the 30-day default would have made it impossible to
     * file the entitlement the law grants. The type's own ceiling is still
     * enforced in LeaveService against the days actually counted.
     */
    private function maximumSpan(): int
    {
        $configured = (int) config('workforce.leave_max_days');
        $entitlement = LeaveType::query()->find($this->integer('leave_type_id'))?->maximumDaysPerRequest();

        return max($configured, (int) ceil((float) $entitlement));
    }
}
