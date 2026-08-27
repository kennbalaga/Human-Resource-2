<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class RememberedLoginService
{
    private const COOKIE_NAME = 'hrms_remembered_employee';

    private const COOKIE_MINUTES = 60 * 24 * 30;

    /**
     * The identifier this browser asked to keep — whichever of the two the
     * form accepts, employee number or work email, was actually typed.
     */
    public function identifier(Request $request): ?string
    {
        return $this->normalize((string) $request->cookie(self::COOKIE_NAME));
    }

    public function login(User $user, bool $remember, ?string $identifier = null): void
    {
        $guard = Auth::guard('web');
        $guard->login($user, false);
        $guard->getProvider()->updateRememberToken($user, Str::random(60));
        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        if (! $remember) {
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));

            return;
        }

        $this->rememberIdentity($user, $identifier);
    }

    /**
     * Keeps the identifier exactly as it was typed when the form can take it
     * back, and only falls back to the employee number when there is nothing
     * usable to keep. The password is never part of this.
     */
    public function rememberIdentity(User $user, ?string $identifier = null): void
    {
        $value = $this->normalize((string) $identifier) ?? $this->employeeNumber($user);

        if ($value === null) {
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));

            return;
        }

        Cookie::queue(
            self::COOKIE_NAME,
            $value,
            self::COOKIE_MINUTES,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }

    /**
     * Extends what is already kept rather than rewriting it: a sign-in this
     * browser did not type for itself must not replace the identifier the
     * person chose to be shown.
     */
    public function refreshIdentity(Request $request, User $user): void
    {
        $this->rememberIdentity($user, $this->identifier($request));
    }

    private function employeeNumber(User $user): ?string
    {
        $employeeNumber = $user->employee()->value('employee_number');

        return is_string($employeeNumber) && $employeeNumber !== ''
            ? Str::upper($employeeNumber)
            : null;
    }

    private function normalize(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || mb_strlen($identifier) > 255) {
            return null;
        }

        if (str_contains($identifier, '@')) {
            $email = Str::lower($identifier);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        }

        $employeeNumber = Str::upper($identifier);

        return mb_strlen($employeeNumber) <= 50
            && preg_match('/\A[A-Z0-9-]+\z/', $employeeNumber) === 1
                ? $employeeNumber
                : null;
    }
}
