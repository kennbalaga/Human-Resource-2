<?php

use App\Http\Controllers\AdminTwoFactorController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Attendance\AttendanceApprovalController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceOverrideController;
use App\Http\Controllers\Attendance\AttendanceQrScanController;
use App\Http\Controllers\Attendance\BiometricSimulatorController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BurnoutRiskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeaveAttachmentController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\Schedule\AiScheduleRecommendationController;
use App\Http\Controllers\Schedule\RecurringScheduleController;
use App\Http\Controllers\Schedule\RoomBoardController;
use App\Http\Controllers\Schedule\RoomBookingController;
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
use App\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

/*
 * Every route that hands out a file: password re-entry first, then a per-user
 * rate limit. `$auditedDownload` adds the audit row for the downloads that do
 * not already write their own (reports and payslips do).
 */
$download = ['download.confirm', 'throttle:downloads'];
$auditedDownload = [...$download, 'download.audit'];

/*
 * Public on purpose: the privacy notice is linked from the login footer, and
 * the people who most need to read it are the ones deciding whether to sign in
 * at all. Guarding it behind `auth` would make it unreadable to exactly them.
 *
 * A guest is sent to the sign-in form with the notice already open over it,
 * because that is where they were and what they were in the middle of. Two
 * readers get the document itself instead: anyone who asks for it plainly —
 * which is what the modal's "Open the full page" link, and every print,
 * bookmark and forwarded link, does — and anyone already signed in, who has no
 * sign-in form left to read it over.
 */
Route::get('/privacy-policy', function (Request $request) {
    if ($request->boolean('plain') || $request->user()) {
        return response()->view('legal.privacy-policy');
    }

    return redirect()->route('login', ['privacy' => 1]);
})->name('privacy-policy');

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->prefix('attendance')->name('attendance.')->group(function () use ($download) {
    Route::get('/', [AttendanceController::class, 'index'])->name('index');
    Route::get('/state', [AttendanceController::class, 'state'])->name('state');
    Route::post('/qr-scan', [AttendanceQrScanController::class, 'store'])->name('qr-scan.store');
    Route::post('/check-in', [AttendanceController::class, 'checkIn'])->name('check-in');
    Route::post('/check-out', [AttendanceController::class, 'checkOut'])->name('check-out');
    /*
     * The attendance report moved under /reports, but these four URLs are
     * linked from the dashboard, both exception panels, the welcome tour and
     * DailyExceptionsService, and they are bookmarked. They keep serving their
     * screen directly rather than redirecting -- the report and, for the
     * exports, the format the path used to encode are pinned as route defaults.
     */
    Route::get('/reports', [ReportController::class, 'show'])
        ->defaults('report', 'attendance')->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])
        ->defaults('report', 'attendance')->defaults('format', 'csv')->middleware($download)->name('reports.export');
    Route::get('/reports/export-pdf', [ReportController::class, 'export'])
        ->defaults('report', 'attendance')->defaults('format', 'pdf')->middleware($download)->name('reports.export-pdf');
    Route::get('/reports/export-excel', [ReportController::class, 'export'])
        ->defaults('report', 'attendance')->defaults('format', 'xlsx')->middleware($download)->name('reports.export-excel');
    Route::post('/records/{attendanceRecord}/approve', [AttendanceApprovalController::class, 'approve'])->name('records.approve');
    Route::post('/records/{attendanceRecord}/reject', [AttendanceApprovalController::class, 'reject'])->name('records.reject');
    Route::get('/override', [AttendanceOverrideController::class, 'index'])->name('override.index');
    Route::post('/override/check-in', [AttendanceOverrideController::class, 'checkIn'])->name('override.check-in');
    Route::post('/override/check-out', [AttendanceOverrideController::class, 'checkOut'])->name('override.check-out');
});

Route::middleware('auth')->group(function () use ($download, $auditedDownload) {
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/organization', [EmployeeController::class, 'index'])->name('organization.index');
    Route::resource('employees', EmployeeController::class)->except('destroy');
    Route::post('/employees/{employee}/archive', [EmployeeController::class, 'archive'])->name('employees.archive');
    Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])->name('employees.restore');
    Route::post('/employees/{employee}/two-factor/reset', [AdminTwoFactorController::class, 'reset'])->name('employees.two-factor.reset');
    Route::post('/employees/{employee}/attendance-qr/reissue', [EmployeeController::class, 'reissueAttendanceQr'])->name('employees.attendance-qr.reissue');
    Route::resource('departments', DepartmentController::class)->except(['show', 'destroy']);
    Route::put('/departments/{department}/shift-coverage', [DepartmentController::class, 'updateShiftRequirements'])->name('departments.shift-coverage.update');
    Route::resource('positions', PositionController::class)->except(['show', 'destroy']);
    Route::resource('rooms', RoomController::class)->except(['show', 'destroy']);
    Route::put('/rooms/{room}/shift-coverage', [RoomController::class, 'updateShiftRequirements'])->name('rooms.shift-coverage.update');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/attendance-qr/download', [ProfileController::class, 'downloadAttendanceQr'])->middleware($auditedDownload)->name('profile.attendance-qr.download');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings/account', [SettingsController::class, 'updateAccount'])->name('settings.account.update');
    Route::patch('/settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences.update');
    Route::patch('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme.update');
    Route::patch('/settings/system/employee-numbers', [SettingsController::class, 'updateEmployeeNumberSettings'])->name('settings.employee-numbers.update');
    Route::patch('/settings/system/two-factor-enforcement', [SettingsController::class, 'updateTwoFactorEnforcementSettings'])->name('settings.two-factor-enforcement.update');
    Route::patch('/settings/system/notification-emails', [SettingsController::class, 'updateNotificationEmailSettings'])->name('settings.notification-emails.update');
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
    Route::get('/schedules/day-roster', [ScheduleCalendarController::class, 'dayRoster'])->name('schedules.day-roster');
    Route::get('/schedules/rooms', [RoomBoardController::class, 'index'])->name('schedules.rooms.index');
    Route::get('/schedules/rooms/{room}/{shift}/candidates', [RoomBoardController::class, 'candidates'])->name('schedules.rooms.candidates');
    Route::post('/schedules/rooms/assign', [RoomBoardController::class, 'store'])->name('schedules.rooms.store');
    Route::delete('/schedules/rooms/{scheduleAssignment}', [RoomBoardController::class, 'destroy'])->name('schedules.rooms.destroy');
    Route::post('/schedules/rooms/{room}/bookings', [RoomBookingController::class, 'store'])->name('schedules.room-bookings.store');
    Route::post('/schedules/room-bookings/{roomBooking}/staff', [RoomBookingController::class, 'attach'])->name('schedules.room-bookings.attach');
    Route::delete('/schedules/room-bookings/{roomBooking}', [RoomBookingController::class, 'destroy'])->name('schedules.room-bookings.destroy');
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
    Route::get('/timesheets/export', [TimesheetController::class, 'export'])->middleware($auditedDownload)->name('timesheets.export');
    Route::post('/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
    Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
    Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');

    Route::get('/payslips', [PayslipController::class, 'index'])->name('payslips.index');
    Route::get('/payslips/{employee}/{period}', [PayslipController::class, 'show'])
        ->where('period', '\d{4}-\d{2}-[12]')->name('payslips.show');
    Route::get('/payslips/{employee}/{period}/pdf', [PayslipController::class, 'download'])
        ->where('period', '\d{4}-\d{2}-[12]')->middleware($download)->name('payslips.download');

    Route::get('/leaves', [LeaveController::class, 'index'])->name('leaves.index');
    Route::post('/leaves', [LeaveController::class, 'store'])->name('leaves.store');
    Route::post('/leave-types', [LeaveController::class, 'storeType'])->name('leave-types.store');
    Route::post('/leaves/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leaves.approve');
    Route::post('/leaves/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leaves.reject');
    Route::post('/leaves/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leaves.cancel');
    Route::get('/leave-attachments/{leaveAttachment}', [LeaveAttachmentController::class, 'download'])->middleware($auditedDownload)->name('leave-attachments.download');

    /*
     * /reports opens the attendance report rather than a landing page listing
     * the three. The tab strip on the report itself already moves between them,
     * so a hub in front of it was a click that only ever led to the same place.
     */
    Route::get('/reports', [ReportController::class, 'show'])
        ->defaults('report', 'attendance')->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])
        ->whereIn('report', ReportRegistry::keys())->name('reports.show');
    Route::get('/reports/{report}/print', [ReportController::class, 'print'])
        ->whereIn('report', ReportRegistry::keys())->middleware('download.confirm')->name('reports.print');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])
        ->whereIn('report', ReportRegistry::keys())->middleware($download)->name('reports.export');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/burnout-risk', [BurnoutRiskController::class, 'index'])->name('analytics.burnout-risk');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->middleware($auditedDownload)->name('analytics.export');
    Route::post('/analytics/ai-insights', [AnalyticsController::class, 'aiInsights'])->name('analytics.ai-insights');

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::patch('/integrations/ai-scheduling', [IntegrationController::class, 'updateAiScheduling'])->name('integrations.ai-scheduling.update');
    Route::post('/integrations/gemini/test', [IntegrationController::class, 'testGemini'])->middleware('throttle:5,1')->name('integrations.gemini.test');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->middleware($auditedDownload)->name('audit-logs.export');
});

require __DIR__.'/auth.php';
