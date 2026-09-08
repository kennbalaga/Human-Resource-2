<?php

use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\AttendanceReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:clear-resets')->everyFifteenMinutes();

Artisan::command('notifications:prune', function () {
    $deleted = DatabaseNotification::query()
        ->where('created_at', '<', now()->subWeek())
        ->delete();

    $this->info("Notifications older than one week pruned: {$deleted}");
})->purpose('Delete notifications older than one week');

Schedule::command('notifications:prune')->daily();

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

/*
 * Proof that the mail path works, on demand.
 *
 * Notification mail is queued, so a broken SMTP configuration is invisible in
 * the browser: the notification appears in the bell, the request succeeds, and
 * the failure surfaces only in a worker's log — or nowhere at all, if no worker
 * is running. This sends the real notification template through the real
 * mailer, right now, on this process, so a refused Gmail login or a wrong app
 * password comes back as an error in the terminal instead of silence.
 */
Artisan::command('notifications:test-email {email}', function (string $email) {
    // Unsaved on purpose: this is a delivery test, so it addresses the mail
    // without writing a notification record for a person who does not exist.
    $recipient = new User(['name' => 'HRMS delivery test', 'email' => $email]);

    $recipient->notifyNow(new SecurityAlertNotification(
        'HRMS email delivery test',
        'If you are reading this, HRMS can send notification email to this address.',
        'Open HRMS',
        url('/dashboard'),
    ));

    $this->info('Sent through the ['.config('mail.default')."] mailer to {$email}.");
})->purpose('Send one real notification email to check the mail configuration');
