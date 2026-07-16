<?php

namespace Tests\Feature\Profile;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_schedule_emails_follow_the_master_and_schedule_switches(): void
    {
        $manager = $this->user('hr.manager@hrms.local');
        $employee = $this->user('employee@hrms.local');
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
        $this->setPreferences($employee, email: true, schedule: true);

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
        $this->setPreferences($employee, email: false, schedule: true);

        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $employee->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-10-05',
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_leave_submission_and_review_send_status_emails_when_enabled(): void
    {
        $employee = $this->user('employee@hrms.local');
        $manager = $this->user('hr.manager@hrms.local');
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();
        $this->setPreferences($employee, email: true, leave: true);

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

    public function test_attendance_reminders_are_preference_aware_and_not_duplicated(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-11-08 07:45:00', 'Asia/Manila'));
        $employee = $this->user('employee@hrms.local');
        $this->setPreferences($employee, email: true, attendance: true);

        $this->artisan('attendance:remind check-in')->assertSuccessful();
        $this->artisan('attendance:remind check-in')->assertSuccessful();

        Notification::assertSentToTimes($employee, PreferenceMailNotification::class, 1);
        Notification::assertSentTo(
            $employee,
            PreferenceMailNotification::class,
            fn (PreferenceMailNotification $notification) => $notification->subject === 'Attendance check-in reminder',
        );

        Notification::fake();
        $this->setPreferences($employee, email: true, attendance: false);
        Carbon::setTestNow(Carbon::parse('2027-11-09 07:45:00', 'Asia/Manila'));

        $this->artisan('attendance:remind check-in')->assertSuccessful();

        Notification::assertNotSentTo($employee, PreferenceMailNotification::class);
    }

    private function user(string $email): User
    {
        return User::query()->with('employee')->where('email', $email)->firstOrFail();
    }

    private function setPreferences(
        User $user,
        bool $email,
        bool $attendance = true,
        bool $schedule = true,
        bool $leave = true,
    ): void {
        $user->preference()->updateOrCreate([], [
            'timezone' => 'Asia/Manila',
            'theme' => 'system',
            'email_notifications' => $email,
            'attendance_reminders' => $attendance,
            'schedule_updates' => $schedule,
            'leave_updates' => $leave,
            'compact_navigation' => false,
            'reduce_motion' => false,
        ]);

        $user->unsetRelation('preference');
    }
}
