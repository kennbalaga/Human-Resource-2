<?php

use App\Services\AttendanceReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:clear-resets')->everyFifteenMinutes();

Artisan::command('attendance:remind {type}', function (string $type) {
    $sent = app(AttendanceReminderService::class)->send($type);
    $this->info("Attendance reminders sent: {$sent}");
})->purpose('Send preference-aware attendance reminders');

Schedule::command('attendance:remind check-in')
    ->weekdays()
    ->at('07:45')
    ->timezone(config('attendance.default_location.timezone'));

Schedule::command('attendance:remind check-out')
    ->weekdays()
    ->at('17:00')
    ->timezone(config('attendance.default_location.timezone'));
