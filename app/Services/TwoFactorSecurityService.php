<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class TwoFactorSecurityService
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function isRequiredFor(User $user): bool
    {
        $requiredRoles = config('security.two_factor.required_roles', []);

        return $requiredRoles !== []
            && $user->roles()->whereIn('slug', $requiredRoles)->exists();
    }

    public function confirmCurrentPassword(User $user, string $password, string $field = 'current_password'): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                $field => ['The current password is incorrect.'],
            ]);
        }
    }

    public function verify(User $user, string $value, bool $consumeRecoveryCode = true): bool
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        $value = trim($value);
        $numericCode = preg_replace('/\s+/', '', $value);

        if (is_string($numericCode) && preg_match('/^\d{6}$/', $numericCode) === 1) {
            return $this->provider->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $numericCode,
            );
        }

        foreach ($user->recoveryCodes() as $recoveryCode) {
            if (hash_equals($recoveryCode, $value)) {
                if ($consumeRecoveryCode) {
                    $user->replaceRecoveryCode($recoveryCode);
                }

                return true;
            }
        }

        return false;
    }

    public function ensureValid(User $user, string $value, string $field = 'verification_code'): void
    {
        if (! $this->verify($user, $value)) {
            throw ValidationException::withMessages([
                $field => ['Enter a valid authenticator or recovery code.'],
            ]);
        }
    }
}
