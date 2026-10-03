<?php

use App\Http\Controllers\Api\BiometricPunchController;
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
use App\Http\Middleware\VerifyBiometricBridgeSignature;
use Illuminate\Support\Facades\Route;

// Deliberately outside the v1 prefix. The bridge agent is already written
// against this exact path, it ships as a separate Python process on hospital
// hardware rather than as a client of the public API, and it authenticates by
// body signature instead of a Sanctum token -- so it shares neither the
// versioning nor the middleware stack of the routes below.
//
// CSRF needs no exclusion here: api.php routes are stateless and the web
// middleware group, which is what verifies the token, never runs on them.
Route::post('/biometric/punches', [BiometricPunchController::class, 'store'])
    ->middleware([
        'throttle:biometric-bridge',
        VerifyBiometricBridgeSignature::class,
        'throttle:biometric-bridge-device',
    ])
    ->name('api.biometric.punches.store');

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
