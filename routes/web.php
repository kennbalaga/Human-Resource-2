<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Attendance\AttendanceApprovalController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceReportController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeaveAttachmentController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\Schedule\RecurringScheduleController;
use App\Http\Controllers\Schedule\ScheduleAssignmentController;
use App\Http\Controllers\Schedule\ScheduleCalendarController;
use App\Http\Controllers\Schedule\ShiftController;
use App\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/dashboard', DashboardController::class)
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->prefix('attendance')->name('attendance.')->group(function () {
    Route::get('/', [AttendanceController::class, 'index'])->name('index');
    Route::post('/check-in', [AttendanceController::class, 'checkIn'])->name('check-in');
    Route::post('/check-out', [AttendanceController::class, 'checkOut'])->name('check-out');
    Route::get('/reports', [AttendanceReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [AttendanceReportController::class, 'export'])->name('reports.export');
    Route::post('/records/{attendanceRecord}/approve', [AttendanceApprovalController::class, 'approve'])->name('records.approve');
    Route::post('/records/{attendanceRecord}/reject', [AttendanceApprovalController::class, 'reject'])->name('records.reject');
});

Route::middleware('auth')->group(function () {
    Route::get('/schedules', [ScheduleCalendarController::class, 'index'])->name('schedules.index');
    Route::get('/schedules/events', [ScheduleCalendarController::class, 'events'])->name('schedules.events');
    Route::post('/schedules/conflicts', [ScheduleCalendarController::class, 'conflicts'])->name('schedules.conflicts');
    Route::post('/schedules', [ScheduleAssignmentController::class, 'store'])->name('schedules.store');
    Route::put('/schedules/{scheduleAssignment}', [ScheduleAssignmentController::class, 'update'])->name('schedules.update');
    Route::delete('/schedules/{scheduleAssignment}', [ScheduleAssignmentController::class, 'destroy'])->name('schedules.destroy');
    Route::post('/recurring-schedules', [RecurringScheduleController::class, 'store'])->name('recurring-schedules.store');
    Route::delete('/recurring-schedules/{recurringSchedule}', [RecurringScheduleController::class, 'destroy'])->name('recurring-schedules.destroy');

    Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');

    Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
    Route::get('/timesheets/export', [TimesheetController::class, 'export'])->name('timesheets.export');
    Route::post('/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
    Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
    Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');

    Route::get('/leaves', [LeaveController::class, 'index'])->name('leaves.index');
    Route::post('/leaves', [LeaveController::class, 'store'])->name('leaves.store');
    Route::post('/leaves/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leaves.approve');
    Route::post('/leaves/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leaves.reject');
    Route::post('/leaves/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leaves.cancel');
    Route::get('/leave-attachments/{leaveAttachment}', [LeaveAttachmentController::class, 'download'])->name('leave-attachments.download');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    Route::post('/analytics/ai-insights', [AnalyticsController::class, 'aiInsights'])->name('analytics.ai-insights');

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations/zapier/test', [IntegrationController::class, 'testZapier'])->name('integrations.zapier.test');
    Route::post('/integrations/zoom/meetings', [IntegrationController::class, 'createZoomMeeting'])->name('integrations.zoom.meetings.store');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
});

require __DIR__.'/auth.php';
