<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $user = User::query()->with(['employee.department', 'employee.position', 'roles'])
            ->whereHas('employee', fn ($query) => $query->where('employee_number', strtoupper($validated['employee_id'])))
            ->first();

        if (! $user || ! $user->is_active || $user->employee?->employment_status !== 'active' || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['employee_id' => ['The provided credentials are incorrect.']]);
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
