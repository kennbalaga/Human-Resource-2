<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One verb on one roster row.
 *
 * Four actions rather than four routes, because the roster row carries four
 * buttons and they are all the same verb on the same record:
 *
 *   capture    somebody took this person's finger at the terminal
 *   release    the template is gone and has to be taken again, PIN kept
 *   deactivate retire the enrolment, keeping the row as the record that this
 *              PIN was once issued to this person
 *   reactivate take a retired enrolment up again
 *
 * There is deliberately no delete. The row is what a disputed punch is traced
 * through, and destroying it destroys the only record the PIN was ever issued.
 */
class UpdateBiometricEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['capture', 'release', 'deactivate', 'reactivate'])],
        ];
    }
}
