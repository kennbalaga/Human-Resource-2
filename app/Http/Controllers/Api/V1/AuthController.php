<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use App\Services\TwoFactorSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function token(Request $request, TwoFactorSecurityService $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string', 'max:30'],
            'recovery_code' => ['nullable', 'string', 'max:50'],
        ]);
        $identifier = trim((string) $validated['employee_id']);
        $user = User::query()->with(['employee.department', 'employee.position', 'roles'])
            ->where(function ($query) use ($identifier): void {
                $query->whereRaw('LOWER(email) = ?', [Str::lower($identifier)])
                    ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('employee_number', Str::upper($identifier)));
            })
            ->first();

        // Hashed unconditionally, including when no such account exists: bcrypt
        // dominates the cost of this endpoint, so skipping it for an unknown
        // employee number makes that case answer measurably faster and turns
        // the token endpoint into a staff-enumeration oracle. Same reasoning as
        // LoginRequest::authenticate().
        $passwordMatches = Hash::check(
            $validated['password'],
            $user?->password ?? self::unknownAccountHash(),
        );

        if (! $user || ! $user->is_active || $user->employee?->employment_status !== 'active' || ! $passwordMatches) {
            Log::notice('HRMS API authentication failure.', [
                'event' => 'authentication.failure',
                'employee_id_hash' => hash_hmac(
                    'sha256',
                    Str::upper(trim((string) $validated['employee_id'])),
                    (string) config('app.key', 'missing-app-key'),
                ),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw ValidationException::withMessages(['employee_id' => ['The provided credentials are incorrect.']]);
        }

        if ($twoFactor->isRequiredFor($user) && ! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['This privileged account must enroll in two-factor authentication through Account Settings before an API token can be created.'],
            ]);
        }

        if ($twoFactor->challengeRequiredFor($user)) {
            $verificationCode = (string) ($validated['two_factor_code'] ?? $validated['recovery_code'] ?? '');

            if ($verificationCode === '' || ! $twoFactor->verify($user, $verificationCode)) {
                Log::notice('HRMS API two-factor authentication failure.', [
                    'event' => 'two_factor.failure',
                    'user_id' => $user->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                throw ValidationException::withMessages([
                    'two_factor_code' => ['A valid authenticator or recovery code is required.'],
                ]);
            }
        }

        $manager = $user->roles->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty();
        $abilities = $manager
            ? ['workforce:read', 'workforce:write', 'analytics:read']
            : ['workforce:read', 'leave:write', 'timesheet:write'];
        $token = $user->createToken($validated['device_name'], $abilities);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in_minutes' => config('sanctum.expiration'),
                'abilities' => $abilities,
                'user' => new EmployeeResource($user->employee),
            ],
        ], 201);
    }

    /**
     * A valid bcrypt digest no password produces, for the comparison above when
     * the account is missing. Held in a static so it costs one hash per process
     * rather than one per failed attempt — otherwise the unknown-account branch
     * becomes the slower one and leaks the same fact in reverse. Built with
     * password_hash() rather than the Hash facade because it is spent, never
     * stored.
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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'API token revoked.']);
    }
}
