<?php

namespace App\Services;

use App\Models\User;
use App\Services\Organization\TwoFactorEnforcementSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Throwable;

class TwoFactorSecurityService
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
        private readonly TwoFactorEnforcementSettings $enforcementSettings,
    ) {}

    public function isRequiredFor(User $user): bool
    {
        if (! $this->enforcementSettings->enabled()) {
            return false;
        }

        $requiredRoles = config('security.two_factor.required_roles', []);

        return $requiredRoles !== []
            && $user->roles->pluck('slug')->intersect($requiredRoles)->isNotEmpty();
    }

    public function challengeRequiredFor(User $user): bool
    {
        return $this->enforcementSettings->enabled() && $user->hasEnabledTwoFactorAuthentication();
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
            try {
                return $this->provider->verify(
                    Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                    $numericCode,
                );
            } catch (Throwable $exception) {
                $this->reportUnreadableEnrollment($user, $exception);

                return false;
            }
        }

        try {
            foreach ($user->recoveryCodes() as $recoveryCode) {
                if (hash_equals($recoveryCode, $value)) {
                    if ($consumeRecoveryCode) {
                        $user->replaceRecoveryCode($recoveryCode);
                    }

                    return true;
                }
            }
        } catch (Throwable $exception) {
            $this->reportUnreadableEnrollment($user, $exception);
        }

        return false;
    }

    /**
     * Normalize an unfinished enrollment created with legacy string encryption.
     * If it belongs to another APP_KEY, it is safe to discard because it was
     * never confirmed and therefore never protected the account.
     *
     * @return 'unchanged'|'repaired'|'reset'
     */
    public function normalizePendingEnrollment(User $user): string
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            return 'unchanged';
        }

        try {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            $recoveryCodes = Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes);

            if ($this->validSecret($secret) && $this->validRecoveryCodes($recoveryCodes)) {
                return 'unchanged';
            }
        } catch (Throwable) {
            // Try the legacy encryptString format below.
        }

        try {
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret, false);
            $recoveryCodes = Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes, false);

            if (! $this->validSecret($secret) || ! $this->validRecoveryCodes($recoveryCodes)) {
                throw new \UnexpectedValueException('The pending 2FA enrollment has an invalid payload.');
            }

            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt($recoveryCodes),
            ])->save();

            Log::notice('A pending 2FA enrollment was upgraded to the current encryption format.', [
                'user_id' => $user->id,
            ]);

            return 'repaired';
        } catch (Throwable $exception) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            Log::warning('An unreadable, unconfirmed 2FA enrollment was safely reset.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return 'reset';
        }
    }

    public function ensureValid(User $user, string $value, string $field = 'verification_code'): void
    {
        if (! $this->verify($user, $value)) {
            throw ValidationException::withMessages([
                $field => ['Enter a valid authenticator or recovery code.'],
            ]);
        }
    }

    private function validSecret(mixed $secret): bool
    {
        return is_string($secret) && preg_match('/^[A-Z2-7]{16,128}$/', $secret) === 1;
    }

    private function validRecoveryCodes(mixed $recoveryCodes): bool
    {
        if (! is_string($recoveryCodes)) {
            return false;
        }

        $decoded = json_decode($recoveryCodes, true);

        return is_array($decoded)
            && $decoded !== []
            && collect($decoded)->every(fn ($code) => is_string($code) && $code !== '');
    }

    private function reportUnreadableEnrollment(User $user, Throwable $exception): void
    {
        Log::warning('A 2FA challenge failed because the enrollment could not be decrypted.', [
            'user_id' => $user->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
