<?php

namespace App\Http\Requests\Leave;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    /**
     * Leave self-service belongs to regular staff. Administrators and HR
     * managers review requests instead of filing their own.
     *
     * @var array<int, string>
     */
    public const ROLES_WITHOUT_SELF_SERVICE = ['system-administrator', 'hr-manager'];

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

                    if ($this->date('start_date')->diffInDays($this->date('end_date')) > config('workforce.leave_max_days')) {
                        $fail('A leave request may not exceed '.config('workforce.leave_max_days').' calendar days.');
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('workforce.attachment_max_kilobytes')],
        ];
    }
}
