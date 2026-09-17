<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Signs in for this browser session only. Nothing about the account outlives
 * it: the framework's persistent login is never issued, and the identifier
 * cookie the retired "Remember Me" box used to set is cleared on every sign-in
 * so shared workstations stop offering the last person's ID.
 */
class RememberedLoginService
{
    private const LEGACY_IDENTIFIER_COOKIE = 'hrms_remembered_employee';

    public function login(User $user): void
    {
        $guard = Auth::guard('web');
        $guard->login($user, false);
        $guard->getProvider()->updateRememberToken($user, Str::random(60));
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
        Cookie::queue(Cookie::forget(self::LEGACY_IDENTIFIER_COOKIE));
    }
}
