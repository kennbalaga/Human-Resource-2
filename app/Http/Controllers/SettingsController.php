<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Http\Requests\Settings\UpdateAttendanceCaptureSettingsRequest;
use App\Http\Requests\Settings\UpdateAttendanceScheduleSettingsRequest;
use App\Http\Requests\Settings\UpdateEmployeeNumberSettingsRequest;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use App\Http\Requests\Settings\UpdatePreferencesRequest;
use App\Http\Requests\Settings\UpdateThemeRequest;
use App\Http\Requests\Settings\UpdateTwoFactorEnforcementSettingsRequest;
use App\Models\BiometricScanEvent;
use App\Models\Employee;
use App\Services\AttendanceCaptureSettings;
use App\Services\Organization\EmployeeNumberSettings;
use App\Services\Organization\TwoFactorEnforcementSettings;
use App\Services\Scheduling\AttendanceScheduleSettings;
use App\Services\TwoFactorSecurityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(
        Request $request,
        TwoFactorSecurityService $twoFactor,
        EmployeeNumberSettings $employeeNumberSettings,
        AttendanceCaptureSettings $attendanceCaptureSettings,
        TwoFactorEnforcementSettings $twoFactorEnforcementSettings,
        AttendanceScheduleSettings $attendanceScheduleSettings,
    ): View {
        $user = $request->user()->load(['roles', 'employee', 'preference']);
        $pendingEncryptionState = $twoFactor->normalizePendingEnrollment($user);
        $user->refresh()->load(['roles', 'employee', 'preference']);
        $attendanceCaptureMode = $attendanceCaptureSettings->mode();
        $attendanceManualModeExpiresAt = $attendanceCaptureMode === AttendanceCaptureSettings::EMERGENCY_MANUAL
            ? $attendanceCaptureSettings->expiresAt()
            : null;

        return view('settings.edit', [
            'user' => $user,
            'preference' => $user->preference,
            'currentRole' => $user->roles->pluck('name')->join(', ') ?: 'Employee',
            'notifications' => collect(),
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'twoFactorRequired' => $twoFactor->isRequiredFor($user),
            'twoFactorSetupReset' => $pendingEncryptionState === 'reset',
            'twoFactorQrCode' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null
                ? $user->twoFactorQrCodeSvg()
                : null,
            'canManageEmployeeNumberSettings' => $user->hasRole('system-administrator'),
            'canManageAttendanceSettings' => $user->hasRole('system-administrator'),
            'canManageTwoFactorEnforcement' => $user->hasRole('system-administrator'),
            'twoFactorEnforcementEnabled' => $twoFactorEnforcementSettings->enabled(),
            'twoFactorEnforcementUpdatedBy' => $twoFactorEnforcementSettings->updatedBy()?->name,
            'canAccessSystemAdministration' => $user->roles->contains(
                fn ($role) => in_array($role->slug, ['system-administrator', 'hr-manager'], true),
            ),
            'employeeNumberAutoGenerate' => $employeeNumberSettings->autoGenerateEnabled(),
            'employeeNumberSettingSource' => $employeeNumberSettings->source(),
            'employeeNumberSettingUpdatedBy' => $employeeNumberSettings->updatedBy()?->name,
            'attendanceCaptureMode' => $attendanceCaptureMode,
            'attendanceCaptureState' => $attendanceCaptureMode.':'.($attendanceManualModeExpiresAt?->getTimestamp() ?? ''),
            'attendanceManualModeReason' => $attendanceCaptureMode === AttendanceCaptureSettings::EMERGENCY_MANUAL
                ? $attendanceCaptureSettings->reason()
                : null,
            'attendanceManualModeExpiresAt' => $attendanceManualModeExpiresAt,
            'attendanceSettingUpdatedBy' => $attendanceCaptureSettings->updatedBy()?->name,
            'attendanceScheduleEarlyWindowMinutes' => $attendanceScheduleSettings->earlyWindowMinutes(),
            'attendanceScheduleGraceMinutes' => $attendanceScheduleSettings->graceMinutes(),
            'attendanceScheduleLateBindMinutes' => $attendanceScheduleSettings->lateBindMinutes(),
            'attendanceScheduleAware' => $attendanceScheduleSettings->scheduleAware(),
            'attendanceScheduleEnforcePublishedShift' => $attendanceScheduleSettings->enforcePublishedShift(),
            'attendanceScheduleSettingUpdatedBy' => $attendanceScheduleSettings->updatedBy()?->name,
            'biometricSimulatorAvailable' => app()->environment(['local', 'testing']) && $user->hasRole('system-administrator'),
            'biometricSimulatorEmployees' => app()->environment(['local', 'testing']) && $user->hasRole('system-administrator')
                ? Employee::query()->where('employment_status', 'active')->orderBy('last_name')->get()
                : collect(),
            'recentBiometricEvents' => app()->environment(['local', 'testing']) && $user->hasRole('system-administrator')
                ? BiometricScanEvent::query()->with(['employee', 'device'])->latest('received_at')->limit(5)->get()
                : collect(),
        ]);
    }

    public function updateAccount(UpdateAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('success', 'Account email updated successfully.');
    }

    public function updatePreferences(UpdatePreferencesRequest $request): RedirectResponse
    {
        $request->user()->preference()->updateOrCreate([], $request->validated());

        return back()->with('success', 'Preferences saved successfully.');
    }

    public function updateTheme(UpdateThemeRequest $request): JsonResponse
    {
        $request->user()->preference()->updateOrCreate([], $request->validated());

        return response()->json(['message' => 'Appearance updated.', 'theme' => $request->validated('theme')]);
    }

    public function updateEmployeeNumberSettings(
        UpdateEmployeeNumberSettingsRequest $request,
        EmployeeNumberSettings $employeeNumberSettings,
    ): RedirectResponse {
        $employeeNumberSettings->update($request->user(), $request->boolean('auto_generate'));

        return back()->with('success', $request->boolean('auto_generate')
            ? 'Automatic employee ID generation enabled.'
            : 'Automatic employee ID generation disabled. New employee IDs must be entered manually.');
    }

    public function updateTwoFactorEnforcementSettings(
        UpdateTwoFactorEnforcementSettingsRequest $request,
        TwoFactorEnforcementSettings $twoFactorEnforcementSettings,
    ): RedirectResponse {
        $enabled = $request->boolean('enabled');
        $twoFactorEnforcementSettings->update($request->user(), $enabled);

        return back()->with('success', $enabled
            ? 'Two-factor authentication enforcement re-enabled.'
            : 'Two-factor authentication enforcement disabled system-wide. Remember to re-enable it before going live.');
    }

    public function updateAttendanceCaptureSettings(
        UpdateAttendanceCaptureSettingsRequest $request,
        AttendanceCaptureSettings $attendanceCaptureSettings,
    ): RedirectResponse {
        $validated = $request->validated();
        $expiresAt = ! empty($validated['manual_mode_expires_at'])
            ? Carbon::parse($validated['manual_mode_expires_at'], config('workforce.timezone'))->utc()
            : null;

        $attendanceCaptureSettings->update(
            $request->user(),
            $validated['capture_mode'],
            $validated['manual_mode_reason'] ?? null,
            $expiresAt,
        );

        return back()->with('success', 'Attendance capture mode updated successfully.');
    }

    public function updateAttendanceScheduleSettings(
        UpdateAttendanceScheduleSettingsRequest $request,
        AttendanceScheduleSettings $attendanceScheduleSettings,
    ): RedirectResponse {
        $attendanceScheduleSettings->update($request->user(), [
            'early_window_minutes' => $request->integer('early_window_minutes'),
            'grace_minutes' => $request->integer('grace_minutes'),
            'late_bind_minutes' => $request->integer('late_bind_minutes'),
            'schedule_aware' => $request->boolean('schedule_aware'),
            'enforce_published_shift' => $request->boolean('enforce_published_shift'),
        ]);

        return back()->with('success', 'Schedule-aware attendance settings updated successfully.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        $request->user()->tokens()->delete();
        $request->session()->regenerate();

        return back()->with('success', 'Password updated. Existing API tokens were revoked.');
    }
}
