<?php

use App\Http\Controllers\AdminTwoFactorController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Attendance\AttendanceApprovalController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceOverrideController;
use App\Http\Controllers\Attendance\AttendanceQrScanController;
use App\Http\Controllers\Attendance\AttendanceReportController;
use App\Http\Controllers\Attendance\BiometricSimulatorController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeaveAttachmentController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Schedule\AiScheduleRecommendationController;
use App\Http\Controllers\Schedule\RecurringScheduleController;
use App\Http\Controllers\Schedule\RosterDraftController;
use App\Http\Controllers\Schedule\ScheduleAssignmentController;
use App\Http\Controllers\Schedule\ScheduleCalendarController;
use App\Http\Controllers\Schedule\ScheduleComplianceController;
use App\Http\Controllers\Schedule\ScheduleDayOffController;
use App\Http\Controllers\Schedule\ScheduleLockController;
use App\Http\Controllers\Schedule\ShiftController;
use App\Http\Controllers\Schedule\ShiftSwapController;
use App\Http\Controllers\SchedulePreferenceController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\TwoFactorSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

/*
 * Public on purpose: the privacy notice is linked from the login footer, and
 * the people who most need to read it are the ones deciding whether to sign in
 * at all. Guarding it behind `auth` would make it unreadable to exactly them.
 */
Route::view('/privacy-policy', 'legal.privacy-policy')->name('privacy-policy');

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->prefix('attendance')->name('attendance.')->group(function () {
    Route::get('/', [AttendanceController::class, 'index'])->name('index');
    Route::get('/state', [AttendanceController::class, 'state'])->name('state');
    Route::post('/qr-scan', [AttendanceQrScanController::class, 'store'])->name('qr-scan.store');
    Route::post('/check-in', [AttendanceController::class, 'checkIn'])->name('check-in');
    Route::post('/check-out', [AttendanceController::class, 'checkOut'])->name('check-out');
    Route::get('/reports', [AttendanceReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [AttendanceReportController::class, 'export'])->name('reports.export');
    Route::get('/reports/export-pdf', [AttendanceReportController::class, 'exportPdf'])->name('reports.export-pdf');
    Route::get('/reports/export-excel', [AttendanceReportController::class, 'exportExcel'])->name('reports.export-excel');
    Route::post('/records/{attendanceRecord}/approve', [AttendanceApprovalController::class, 'approve'])->name('records.approve');
    Route::post('/records/{attendanceRecord}/reject', [AttendanceApprovalController::class, 'reject'])->name('records.reject');
    Route::get('/override', [AttendanceOverrideController::class, 'index'])->name('override.index');
    Route::post('/override/check-in', [AttendanceOverrideController::class, 'checkIn'])->name('override.check-in');
    Route::post('/override/check-out', [AttendanceOverrideController::class, 'checkOut'])->name('override.check-out');
});

Route::middleware('auth')->group(function () {
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/organization', [EmployeeController::class, 'index'])->name('organization.index');
    Route::resource('employees', EmployeeController::class)->except('destroy');
    Route::post('/employees/{employee}/two-factor/reset', [AdminTwoFactorController::class, 'reset'])->name('employees.two-factor.reset');
    Route::post('/employees/{employee}/attendance-qr/reissue', [EmployeeController::class, 'reissueAttendanceQr'])->name('employees.attendance-qr.reissue');
    Route::resource('departments', DepartmentController::class)->except(['show', 'destroy']);
    Route::put('/departments/{department}/shift-coverage', [DepartmentController::class, 'updateShiftRequirements'])->name('departments.shift-coverage.update');
    Route::resource('positions', PositionController::class)->except(['show', 'destroy']);

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/attendance-qr/download', [ProfileController::class, 'downloadAttendanceQr'])->name('profile.attendance-qr.download');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings/account', [SettingsController::class, 'updateAccount'])->name('settings.account.update');
    Route::patch('/settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences.update');
    Route::patch('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme.update');
    Route::patch('/settings/system/employee-numbers', [SettingsController::class, 'updateEmployeeNumberSettings'])->name('settings.employee-numbers.update');
    Route::patch('/settings/system/two-factor-enforcement', [SettingsController::class, 'updateTwoFactorEnforcementSettings'])->name('settings.two-factor-enforcement.update');
    Route::patch('/settings/system/attendance-capture', [SettingsController::class, 'updateAttendanceCaptureSettings'])->name('settings.attendance-capture.update');
    Route::patch('/settings/system/attendance-schedule', [SettingsController::class, 'updateAttendanceScheduleSettings'])->name('settings.attendance-schedule.update');
    Route::post('/settings/system/biometric-simulator', [BiometricSimulatorController::class, 'store'])->name('settings.biometric-simulator.store');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password.update');
    Route::post('/settings/two-factor', [TwoFactorSettingsController::class, 'enable'])->name('two-factor.settings.enable');
    Route::post('/settings/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])->name('two-factor.settings.confirm');
    Route::post('/settings/two-factor/recovery-codes/show', [TwoFactorSettingsController::class, 'recoveryCodes'])->name('two-factor.settings.recovery-codes.show');
    Route::post('/settings/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])->name('two-factor.settings.recovery-codes.regenerate');
    Route::delete('/settings/two-factor', [TwoFactorSettingsController::class, 'disable'])->name('two-factor.settings.disable');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/toggle-read', [NotificationController::class, 'toggleRead'])->name('notifications.toggle-read');
    Route::get('/notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');

    Route::get('/schedules', [ScheduleCalendarController::class, 'index'])->name('schedules.index');
    Route::get('/schedules/events', [ScheduleCalendarController::class, 'events'])->name('schedules.events');
    Route::post('/schedules/conflicts', [ScheduleCalendarController::class, 'conflicts'])->name('schedules.conflicts');
    Route::post('/schedules/roster/evaluate', [RosterDraftController::class, 'evaluate'])->name('schedules.roster.evaluate');
    Route::post('/schedules/roster/fill', [RosterDraftController::class, 'fill'])->name('schedules.roster.fill');
    Route::post('/schedules/roster/suggest', [RosterDraftController::class, 'suggest'])->middleware('throttle:10,1')->name('schedules.roster.suggest');
    Route::post('/schedules/roster/publish', [RosterDraftController::class, 'publish'])->name('schedules.roster.publish');
    Route::get('/schedules/roster/drafts', [RosterDraftController::class, 'drafts'])->name('schedules.roster.drafts');
    Route::post('/schedules/roster/drafts', [RosterDraftController::class, 'saveDraft'])->name('schedules.roster.drafts.save');
    Route::delete('/schedules/roster/drafts/{rosterDraft}', [RosterDraftController::class, 'discardDraft'])->name('schedules.roster.drafts.discard');
    Route::post('/schedule-locks', [ScheduleLockController::class, 'store'])->name('schedule-locks.store');
    Route::delete('/schedule-locks/{scheduleLock}', [ScheduleLockController::class, 'destroy'])->name('schedule-locks.destroy');
    Route::post('/schedule-compliance-reviews', [ScheduleComplianceController::class, 'store'])->name('schedule-compliance-reviews.store');
    Route::post('/schedules/ai-recommendations', [AiScheduleRecommendationController::class, 'store'])->middleware('throttle:10,1')->name('schedules.ai-recommendations.store');
    Route::post('/schedules/ai-recommendations/{scheduleRecommendation}/apply', [AiScheduleRecommendationController::class, 'apply'])->middleware('throttle:20,1')->name('schedules.ai-recommendations.apply');
    Route::post('/schedules/ai-recommendations/{scheduleRecommendation}/decision', [AiScheduleRecommendationController::class, 'decision'])->middleware('throttle:20,1')->name('schedules.ai-recommendations.decision');
    Route::post('/schedules', [ScheduleAssignmentController::class, 'store'])->name('schedules.store');
    Route::put('/schedules/{scheduleAssignment}', [ScheduleAssignmentController::class, 'update'])->name('schedules.update');
    Route::delete('/schedules/{scheduleAssignment}', [ScheduleAssignmentController::class, 'destroy'])->name('schedules.destroy');
    Route::delete('/schedule-day-offs/{scheduleDayOff}', [ScheduleDayOffController::class, 'destroy'])->name('schedule-day-offs.destroy');
    Route::post('/recurring-schedules', [RecurringScheduleController::class, 'store'])->name('recurring-schedules.store');
    Route::delete('/recurring-schedules/{recurringSchedule}', [RecurringScheduleController::class, 'destroy'])->name('recurring-schedules.destroy');

    Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::patch('/shifts/{shift}/toggle-active', [ShiftController::class, 'toggleActive'])->name('shifts.toggle-active');
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');

    Route::get('/shift-swaps', [ShiftSwapController::class, 'index'])->name('shift-swaps.index');
    Route::post('/shift-swaps', [ShiftSwapController::class, 'store'])->name('shift-swaps.store');
    Route::post('/shift-swaps/{shiftSwapRequest}/respond', [ShiftSwapController::class, 'respond'])->name('shift-swaps.respond');
    Route::post('/shift-swaps/{shiftSwapRequest}/approve', [ShiftSwapController::class, 'approve'])->name('shift-swaps.approve');
    Route::post('/shift-swaps/{shiftSwapRequest}/reject', [ShiftSwapController::class, 'reject'])->name('shift-swaps.reject');
    Route::post('/shift-swaps/{shiftSwapRequest}/cancel', [ShiftSwapController::class, 'cancel'])->name('shift-swaps.cancel');

    Route::get('/schedule-preferences', [SchedulePreferenceController::class, 'index'])->name('schedule-preferences.index');
    Route::patch('/schedule-preferences/standing', [SchedulePreferenceController::class, 'updateStandingPreference'])->name('schedule-preferences.update-standing');
    Route::post('/schedule-preferences/day-off', [SchedulePreferenceController::class, 'storeDayOff'])->name('schedule-preferences.store-day-off');
    Route::post('/schedule-preferences/day-off/{preferredDayOff}/approve', [SchedulePreferenceController::class, 'approveDayOff'])->name('schedule-preferences.approve-day-off');
    Route::post('/schedule-preferences/day-off/{preferredDayOff}/reject', [SchedulePreferenceController::class, 'rejectDayOff'])->name('schedule-preferences.reject-day-off');
    Route::post('/schedule-preferences/day-off/{preferredDayOff}/cancel', [SchedulePreferenceController::class, 'cancelDayOff'])->name('schedule-preferences.cancel-day-off');

    Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
    Route::get('/timesheets/export', [TimesheetController::class, 'export'])->name('timesheets.export');
    Route::post('/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
    Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
    Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');

    Route::get('/leaves', [LeaveController::class, 'index'])->name('leaves.index');
    Route::post('/leaves', [LeaveController::class, 'store'])->name('leaves.store');
    Route::post('/leave-types', [LeaveController::class, 'storeType'])->name('leave-types.store');
    Route::post('/leaves/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leaves.approve');
    Route::post('/leaves/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leaves.reject');
    Route::post('/leaves/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leaves.cancel');
    Route::get('/leave-attachments/{leaveAttachment}', [LeaveAttachmentController::class, 'download'])->name('leave-attachments.download');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    Route::post('/analytics/ai-insights', [AnalyticsController::class, 'aiInsights'])->name('analytics.ai-insights');

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::patch('/integrations/ai-scheduling', [IntegrationController::class, 'updateAiScheduling'])->name('integrations.ai-scheduling.update');
    Route::post('/integrations/gemini/test', [IntegrationController::class, 'testGemini'])->middleware('throttle:5,1')->name('integrations.gemini.test');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
});

require __DIR__.'/auth.php';
