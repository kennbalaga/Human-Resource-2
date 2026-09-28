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

/*
 * The terminated-record sweep.
 *
 * Overnight and once a day, because the window it enforces is measured in days
 * — running it more often would only mean archiving somebody at 09:15 instead
 * of at 01:15 on the same date, in the middle of the working morning, while
 * their colleagues have the directory open.
 */
Schedule::command('employees:archive-terminated')
    ->dailyAt('01:15')
    ->timezone(config('workforce.timezone'));

/*
 * The day's burnout risk assessment, made before anyone signs in so the first
 * dashboard of the morning does not have to make it. Every screen makes a
 * missing one on demand, so a night this does not run costs a slower first
 * page load and nothing else.
 */
Schedule::command('burnout:snapshot')
    ->dailyAt('01:30')
    ->timezone(config('workforce.timezone'));

/*
 * Shift swaps nobody answered in time.
 *
 * Overnight, like the other sweeps, and for the same reason: the deadline it
 * enforces is a calendar date, so the only thing an hourly run would buy is
 * closing a request at 09:15 instead of 01:45 on the day it stopped being
 * answerable. Running before the working day does mean a reviewer's queue is
 * already clear of it when they first open the page.
 */
Schedule::command('shift-swaps:expire')
    ->dailyAt('01:45')
    ->timezone(config('schedule.timezone'));

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
