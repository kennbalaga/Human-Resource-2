<?php

namespace Tests\Feature\Profile;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use App\Services\Organization\NotificationEmailSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreferenceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_schedule_emails_follow_the_system_wide_switch(): void
    {
        $manager = $this->user('hr.manager@hrms.local');
        $employee = $this->user('employee@hrms.local');
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $employee->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-10-04',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $employee,
            PreferenceMailNotification::class,
            fn (PreferenceMailNotification $notification) => $notification->subject === 'New work schedule assigned',
        );

        Notification::fake();
        $this->disableNotificationEmails();

        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $employee->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-10-05',
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSent();

        // Paused email is not a silenced app: the notification is still there
        // for anybody who opens HRMS.
        $this->assertSame(2, $employee->notifications()->count());
    }

    public function test_an_employee_cannot_switch_off_their_own_notification_emails(): void
    {
        $employee = $this->user('employee@hrms.local');

        // The old per-account switches, posted by hand rather than through a
        // form that no longer offers them.
        $this->actingAs($employee)->patch(route('settings.preferences.update'), [
            'timezone' => 'Asia/Manila',
            'theme' => 'system',
            'email_notifications' => '0',
            'schedule_updates' => '0',
            'compact_navigation' => '0',
            'reduce_motion' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->emailsEnabled());

        $this->flushSession();
        $manager = $this->user('hr.manager@hrms.local');
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $employee->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-10-06',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($employee, PreferenceMailNotification::class);
    }

    public function test_only_a_system_administrator_can_pause_notification_emails(): void
    {
        $manager = $this->user('hr.manager@hrms.local');

        $this->actingAs($manager)
            ->patch(route('settings.notification-emails.update'), ['enabled' => '0'])
            ->assertForbidden();

        $this->assertTrue($this->emailsEnabled());

        $this->flushSession();
        $administrator = $this->user('admin@hrms.local');

        $this->actingAs($administrator)
            ->patch(route('settings.notification-emails.update'), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse($this->emailsEnabled());
    }

    public function test_saving_the_setting_before_its_migration_has_run_says_so_instead_of_failing(): void
    {
        Schema::drop('notification_email_settings');
        Cache::flush();

        $this->actingAs($this->user('admin@hrms.local'))
            ->patch(route('settings.notification-emails.update'), ['enabled' => '0'])
            ->assertRedirect()
            ->assertSessionHas('warning');

        // The fallback is to keep sending, so nobody stops being reachable
        // because a migration is outstanding.
        $this->assertTrue($this->emailsEnabled());
    }

    public function test_leave_submission_and_review_send_status_emails(): void
    {
        $employee = $this->user('employee@hrms.local');
        $manager = $this->user('hr.manager@hrms.local');
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-11-08',
            'end_date' => '2027-11-10',
            'reason' => 'Planned family leave request.',
        ])->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();
        $this->flushSession();
        $this->actingAs($manager)->post(route('leaves.approve', $leave), [
            'reviewer_notes' => 'Coverage confirmed.',
        ])->assertSessionHasNoErrors();

        $subjects = Notification::sent($employee, PreferenceMailNotification::class)
            ->pluck('subject')
            ->all();

        $this->assertContains('Leave request received', $subjects);
        $this->assertContains('Leave request approved', $subjects);
    }

    public function test_attendance_reminders_reach_everyone_once_and_stop_when_email_is_paused(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-11-08 07:45:00', 'Asia/Manila'));
        $employee = $this->user('employee@hrms.local');

        $this->artisan('attendance:remind check-in')->assertSuccessful();
        $this->artisan('attendance:remind check-in')->assertSuccessful();

        Notification::assertSentToTimes($employee, PreferenceMailNotification::class, 1);
        Notification::assertSentTo(
            $employee,
            PreferenceMailNotification::class,
            fn (PreferenceMailNotification $notification) => $notification->subject === 'Attendance check-in reminder',
        );

        Notification::fake();
        $this->disableNotificationEmails();
        Carbon::setTestNow(Carbon::parse('2027-11-09 07:45:00', 'Asia/Manila'));

        $this->artisan('attendance:remind check-in')->assertSuccessful();

        Notification::assertNotSentTo($employee, PreferenceMailNotification::class);
    }

    private function user(string $email): User
    {
        return User::query()->with('employee')->where('email', $email)->firstOrFail();
    }

    /**
     * Read through a fresh service on a cold cache, the way another process
     * would: the flag is cached for a minute, so a value this test itself
     * warmed would answer for the database rather than about it.
     */
    private function emailsEnabled(): bool
    {
        Cache::flush();

        return app(NotificationEmailSettings::class)->enabled();
    }

    private function disableNotificationEmails(): void
    {
        app(NotificationEmailSettings::class)->update($this->user('admin@hrms.local'), false);
    }
}
