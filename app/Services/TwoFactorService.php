<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FALaravel\Google2FA;

class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function createSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    public function enroll(User $user, string $secret): array
    {
        $recoveryCodes = collect(range(1, 10))->map(fn () => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)))->all();

        $user->recoveryCodes()->delete();
        foreach ($recoveryCodes as $code) {
            $user->recoveryCodes()->create(['code_hash' => Hash::make($code)]);
        }

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_enabled_at' => now(),
            'two_factor_failed_attempts' => 0,
            'two_factor_locked_until' => null,
        ])->save();

        return $recoveryCodes;
    }

    public function validRecoveryCode(User $user, string $code): ?TwoFactorRecoveryCode
    {
        return $user->recoveryCodes()->whereNull('used_at')->get()->first(fn (TwoFactorRecoveryCode $recovery) => Hash::check($code, $recovery->code_hash));
    }

    public function secret(User $user): string
    {
        return Crypt::decryptString((string) $user->two_factor_secret);
    }

    public function audit(Request $request, User $user, string $action, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => $user->id, 'action' => $action, 'route_name' => $request->route()?->getName(),
            'method' => $request->method(), 'path' => $request->path(), 'subject_type' => User::class,
            'subject_id' => $user->id, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            'response_status' => 200, 'metadata' => $metadata,
        ]);
    }
}
