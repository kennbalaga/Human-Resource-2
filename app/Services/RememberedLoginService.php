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

    public function employeeNumber(Request $request): ?string
    {
        $employeeNumber = Str::upper(trim((string) $request->cookie(self::COOKIE_NAME)));

        if ($employeeNumber === '' || mb_strlen($employeeNumber) > 50) {
            return null;
        }

        return preg_match('/\A[A-Z0-9-]+\z/', $employeeNumber) === 1
            ? $employeeNumber
            : null;
    }

    public function login(User $user, bool $remember): void
    {
        $guard = Auth::guard('web');
        $guard->login($user, false);
        $guard->getProvider()->updateRememberToken($user, Str::random(60));
        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        if (! $remember) {
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));

            return;
        }

        $this->rememberIdentity($user);
    }

    public function rememberIdentity(User $user): void
    {
        $employeeNumber = $user->employee()->value('employee_number');

        if (! is_string($employeeNumber) || $employeeNumber === '') {
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));

            return;
        }

        Cookie::queue(
            self::COOKIE_NAME,
            Str::upper($employeeNumber),
            self::COOKIE_MINUTES,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            config('session.same_site', 'lax'),
        );
    }
}
