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

        // The hash comparison runs on every attempt, including the ones where
        // no such employee exists, because bcrypt is the expensive part of this
        // method and short-circuiting past it is measurable from outside. An
        // unknown employee number would otherwise answer noticeably faster than
        // a real one with the wrong password, which turns the sign-in form into
        // a way to enumerate staff — and these employee numbers run in sequence.
        $passwordMatches = Hash::check(
            (string) $this->input('password'),
            $user?->password ?? self::unknownAccountHash(),
        );

        // Evaluated after the hash for the same reason: a suspended account and
        // a non-existent one must cost the same.
        $authenticated = $user !== null
            && $user->is_active
            && $employee->employment_status === 'active'
            && $passwordMatches;

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

    /**
     * A valid bcrypt digest that no password produces, used as the comparison
     * target when the account does not exist.
     *
     * Computed once per process and held in a static: producing a digest is
     * itself a bcrypt round, so minting one per failed attempt would make the
     * missing account the *slower* branch and simply invert the same signal.
     */
    private static function unknownAccountHash(): string
    {
        static $hash = null;

        if ($hash !== null) {
            return $hash;
        }

        // password_hash() directly rather than Hash::make(): this value exists
        // only to be spent, never to be stored or verified, and going through
        // the hasher would make the anti-enumeration path depend on whatever
        // the container currently binds for hashing. The cost still tracks the
        // configured rounds, so raising them does not quietly reopen the gap.
        return $hash = password_hash(
            'hrms/no-such-account/'.Str::random(32),
            PASSWORD_BCRYPT,
            ['cost' => (int) config('hashing.bcrypt.rounds', 12)],
        );
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
