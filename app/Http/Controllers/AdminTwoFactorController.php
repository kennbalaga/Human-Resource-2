<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Services\TwoFactorSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Throwable;

class AdminTwoFactorController extends Controller
{
    public function reset(
        Request $request,
        Employee $employee,
        TwoFactorSecurityService $twoFactor,
        DisableTwoFactorAuthentication $disable,
    ): RedirectResponse {
        abort_unless($request->user()->hasRole('system-administrator'), 403);

        $target = $employee->user;
        abort_if($target === null || $target->is($request->user()), 422, 'Use Account Settings to manage your own two-factor authentication.');

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'identity_verified' => ['accepted'],
        ]);

        $twoFactor->confirmCurrentPassword($request->user(), $validated['current_password']);
        $disable($target);
        $target->tokens()->delete();

        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
        }

        $target->forceFill(['remember_token' => Str::random(60)])->save();
        $this->notifyTarget($target);

        return back()->with('success', 'Two-factor authentication reset after identity verification. Existing sessions and API tokens were revoked.');
    }

    private function notifyTarget(User $user): void
    {
        try {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => self::class,
                'data' => [
                    'title' => 'Two-factor authentication reset',
                    'message' => 'A System Administrator reset your two-factor authentication after identity verification. Enroll again from Account Settings.',
                    'action_text' => 'Open security settings',
                    'action_url' => route('settings.edit').'#two-factor',
                    'tone' => 'warning',
                    'icon' => 'shield',
                    'category' => 'security',
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('The 2FA reset notification could not be stored.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
