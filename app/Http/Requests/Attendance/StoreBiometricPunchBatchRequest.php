<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a batch of punches posted by the bridge agent.
 *
 * Everything here is untrusted. The payload crosses the public internet from
 * an unattended process, and the punches inside it originate on a device
 * nobody in this application controls, so each field is checked on its own
 * rather than taken on the strength of the batch having a valid signature.
 */
class StoreBiometricPunchBatchRequest extends FormRequest
{
    /**
     * The caller is already authenticated by VerifyBiometricBridgeSignature,
     * which runs ahead of this request and rejects an unsigned or mis-signed
     * body with a 401 before any of it is parsed.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Must be a terminal somebody registered and left active. This is
            // the allowlist the handoff brief asks for: an unknown serial is
            // refused here rather than quietly creating a device row, so a
            // stranger who learns the shared secret still cannot invent a
            // terminal to attribute attendance to.
            'device_sn' => [
                'required', 'string', 'max:120',
                Rule::exists('biometric_devices', 'serial_number')
                    ->where(fn (Builder $query) => $query->where('is_active', true)),
            ],
            'sent_at' => ['required', 'date'],

            'punches' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('attendance.biometric_bridge.max_batch_size', 500))],

            // 64 lowercase hex characters: a sha256 digest and nothing else.
            // The column is unique, so a malformed value here would otherwise
            // occupy an idempotency key that can never be matched again.
            'punches.*.fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'punches.*.pin' => ['required', 'string', 'max:120'],

            // No date_format here: the device sends 'Y-m-d H:i:s' local wall
            // clock time, but the agent has been seen to send ISO-8601 in
            // sent_at, and a punch rejected over its separator would be a
            // punch the bridge retries forever. The service parses it against
            // the terminal's own timezone.
            'punches.*.punched_at' => ['required', 'date'],

            // Bounded to the protocol's byte, not to the codes this unit is
            // believed to emit. An unrecognised code is still a real scan and
            // is stored; what it means is decided downstream.
            'punches.*.punch_code' => ['required', 'integer', 'between:0,255'],
            'punches.*.verify_mode' => ['nullable', 'integer', 'between:0,255'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_sn.exists' => 'That serial number is not a registered biometric terminal.',
        ];
    }

    /**
     * The serial in the body must be the one the agent signed as, so a bridge
     * holding the shared secret cannot post on behalf of a terminal it is not
     * standing next to.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $header = (string) $this->headers->get('X-Bridge-Device', '');

            if ($header !== '' && $header !== (string) $this->input('device_sn')) {
                $validator->errors()->add('device_sn', 'The device serial does not match the signing bridge.');
            }
        });
    }
}
