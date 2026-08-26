<?php

namespace App\Http\Requests\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * What was actually typed into the single sign-in field, so that the
     * form can offer the same thing back next time instead of guessing.
     */
    public function identifier(): string
    {
        return trim((string) $this->input('employee_id'));
    }

    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $identifier = $this->identifier();
        $employeeNumber = Str::upper($identifier);
        $email = Str::lower($identifier);
        $employee = Employee::query()
            ->with('user')
            ->where(function ($query) use ($employeeNumber, $email): void {
                $query->where('employee_number', $employeeNumber)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->whereRaw('LOWER(email) = ?', [$email]));
            })
            ->first();

        $user = $employee?->user;
        $authenticated = $user !== null
            && $user->is_active
            && $employee->employment_status === 'active'
            && Hash::check((string) $this->input('password'), $user->password);

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());
            $this->recordSecurityEvent('authentication.failure');

            throw ValidationException::withMessages([
                'employee_id' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => (string) $this->input('password')])->save();
        }

        return $user;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        $this->recordSecurityEvent('rate_limit.triggered');

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'employee_id' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('employee_id')).'|'.$this->ip()
        );
    }

    private function recordSecurityEvent(string $event): void
    {
        Log::notice('HRMS web authentication event.', [
            'event' => $event,
            'employee_id_hash' => hash_hmac(
                'sha256',
                Str::upper($this->identifier()),
                (string) config('app.key', 'missing-app-key'),
            ),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ]);
    }
}
