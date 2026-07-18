<?php

use App\Http\Controllers\Api\V1\AiScheduleRecommendationController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CspReportController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\TimesheetController;
use App\Http\Middleware\EnsureApiTwoFactorEnrollment;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/security/csp-report', CspReportController::class)
        ->middleware('throttle:csp-report')
        ->name('security.csp-report');

    Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:api-login')->name('auth.token');

    Route::middleware(['auth:sanctum', EnsureApiTwoFactorEnrollment::class, 'throttle:api'])->group(function () {
        Route::delete('/auth/token', [AuthController::class, 'logout'])->name('auth.logout');

        Route::apiResource('employees', EmployeeController::class)->only(['index', 'show']);
        Route::apiResource('attendance', AttendanceController::class)->parameters(['attendance' => 'attendanceRecord'])->only(['index', 'show']);
        Route::post('/attendance/{attendanceRecord}/approve', [AttendanceController::class, 'approve'])->name('attendance.approve');
        Route::apiResource('schedules', ScheduleController::class)->parameters(['schedules' => 'scheduleAssignment'])->except(['show']);
        Route::post('/schedule-recommendations', [AiScheduleRecommendationController::class, 'store'])->middleware('throttle:10,1')->name('schedule-recommendations.store');
        Route::post('/schedule-recommendations/{scheduleRecommendation}/apply', [AiScheduleRecommendationController::class, 'apply'])->middleware('throttle:20,1')->name('schedule-recommendations.apply');
        Route::post('/schedule-recommendations/{scheduleRecommendation}/decision', [AiScheduleRecommendationController::class, 'decision'])->middleware('throttle:20,1')->name('schedule-recommendations.decision');
        Route::apiResource('timesheets', TimesheetController::class)->only(['index', 'show']);
        Route::post('/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
        Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
        Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');
        Route::apiResource('leaves', LeaveController::class)->parameters(['leaves' => 'leaveRequest'])->only(['index', 'store', 'show']);
        Route::post('/leaves/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leaves.approve');
        Route::post('/leaves/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leaves.reject');
        Route::post('/leaves/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leaves.cancel');
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    });
});
