<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use App\Services\TwoFactorSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function token(Request $request, TwoFactorSecurityService $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string', 'max:30'],
            'recovery_code' => ['nullable', 'string', 'max:50'],
        ]);
        $user = User::query()->with(['employee.department', 'employee.position', 'roles'])
            ->whereHas('employee', fn ($query) => $query->where('employee_number', strtoupper($validated['employee_id'])))
            ->first();

        if (! $user || ! $user->is_active || $user->employee?->employment_status !== 'active' || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['employee_id' => ['The provided credentials are incorrect.']]);
        }

        if ($twoFactor->isRequiredFor($user) && ! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['This privileged account must enroll in two-factor authentication through Account Settings before an API token can be created.'],
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $verificationCode = (string) ($validated['two_factor_code'] ?? $validated['recovery_code'] ?? '');

            if ($verificationCode === '' || ! $twoFactor->verify($user, $verificationCode)) {
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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'API token revoked.']);
    }
}
