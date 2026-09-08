<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
    }

    public function test_workflow_update_is_visible_in_dropdown_and_notification_center(): void
    {
        $user = $this->user('employee@hrms.local');
        $this->storeScheduleNotification($user);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('1 unread notification')
            ->assertSee('Work schedule updated')
            ->assertSee(route('notifications.index'), false);

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notification center')
            ->assertSee('Work schedule updated');
    }

    public function test_opening_a_notification_marks_it_read_and_uses_its_safe_action(): void
    {
        $user = $this->user('employee@hrms.local');
        $this->storeScheduleNotification($user);
        $notification = $user->unreadNotifications()->firstOrFail();

        $this->actingAs($user)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('schedules.index'));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_can_mark_all_own_notifications_as_read(): void
    {
        $user = $this->user('employee@hrms.local');
        $this->storeScheduleNotification($user);
        $this->storeScheduleNotification($user);

        $this->actingAs($user)
            ->patch(route('notifications.read-all'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_user_cannot_open_another_users_notification(): void
    {
        $owner = $this->user('employee@hrms.local');
        $otherUser = $this->user('hr.manager@hrms.local');
        $this->storeScheduleNotification($owner);
        $notification = $owner->unreadNotifications()->firstOrFail();

        $this->actingAs($otherUser)
            ->get(route('notifications.open', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_notification_center_filters_by_category(): void
    {
        $user = $this->user('employee@hrms.local');
        $this->storeScheduleNotification($user);
        $this->storeLeaveNotification($user);
        $this->storeAttendanceNotification($user);

        // Note: assertions are scoped to the notification-center panel, not the
        // whole page — the header bell dropdown always lists all unread
        // notifications regardless of this page's category filter.
        $attendancePanel = $this->notificationCenterPanel($user, ['category' => 'attendance']);
        $this->assertStringContainsString('Attendance reminder', $attendancePanel);
        $this->assertStringNotContainsString('Work schedule updated', $attendancePanel);
        $this->assertStringNotContainsString('Leave request approved', $attendancePanel);

        $schedulePanel = $this->notificationCenterPanel($user, ['category' => 'schedule']);
        $this->assertStringContainsString('Work schedule updated', $schedulePanel);
        $this->assertStringNotContainsString('Leave request approved', $schedulePanel);
        $this->assertStringNotContainsString('Attendance reminder', $schedulePanel);

        $leavePanel = $this->notificationCenterPanel($user, ['category' => 'leave']);
        $this->assertStringContainsString('Leave request approved', $leavePanel);
        $this->assertStringNotContainsString('Work schedule updated', $leavePanel);
        $this->assertStringNotContainsString('Attendance reminder', $leavePanel);

        $allPanel = $this->notificationCenterPanel($user);
        $this->assertStringContainsString('Work schedule updated', $allPanel);
        $this->assertStringContainsString('Leave request approved', $allPanel);
        $this->assertStringContainsString('Attendance reminder', $allPanel);
    }

    /** @param array<string, string> $query */
    private function notificationCenterPanel(User $user, array $query = []): string
    {
        $html = $this->actingAs($user)->get(route('notifications.index', $query))
            ->assertOk()
            ->getContent();

        preg_match('/<section class="panel notification-center-panel">.*?<\/section>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'Notification center panel markup was not found.');

        return $matches[0];
    }

    public function test_notification_toggle_read_marks_read_then_unread_without_deleting(): void
    {
        $user = $this->user('employee@hrms.local');
        $this->storeScheduleNotification($user);
        $notification = $user->notifications()->firstOrFail();

        $this->assertNull($notification->read_at);

        $this->actingAs($user)
            ->patch(route('notifications.toggle-read', $notification->id))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(1, $user->notifications()->count());

        $this->actingAs($user)
            ->patch(route('notifications.toggle-read', $notification->id))
            ->assertRedirect();

        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_user_cannot_toggle_another_users_notification(): void
    {
        $owner = $this->user('employee@hrms.local');
        $otherUser = $this->user('hr.manager@hrms.local');
        $this->storeScheduleNotification($owner);
        $notification = $owner->unreadNotifications()->firstOrFail();

        $this->actingAs($otherUser)
            ->patch(route('notifications.toggle-read', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_category_filter_tabs_are_always_available_even_when_empty(): void
    {
        $user = $this->user('employee@hrms.local');

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Attendance')
            ->assertSee('Schedule')
            ->assertSee('Leave')
            ->assertSee('Security');
    }

    public function test_empty_dropdown_uses_clear_copy_and_working_footer_link(): void
    {
        $user = $this->user('employee@hrms.local');

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee("You're all caught up", false)
            ->assertSee('No unread notifications right now.')
            ->assertSee(route('notifications.index'), false);
    }

    private function storeScheduleNotification(User $user): void
    {
        app(PreferenceNotificationService::class)->send(
            $user,
            'schedule_updates',
            new PreferenceMailNotification(
                'Work schedule updated',
                ['Your Day Shift schedule was updated.'],
                'View My Schedule',
                route('schedules.index'),
            ),
        );
    }

    private function storeLeaveNotification(User $user): void
    {
        app(PreferenceNotificationService::class)->send(
            $user,
            'leave_updates',
            new PreferenceMailNotification(
                'Leave request approved',
                ['Your leave request was approved.'],
                'View Leave',
                route('leaves.index'),
            ),
        );
    }

    private function storeAttendanceNotification(User $user): void
    {
        app(PreferenceNotificationService::class)->send(
            $user,
            'attendance_reminders',
            new PreferenceMailNotification(
                'Attendance reminder',
                ['You have not clocked in for your shift.'],
                'View Attendance',
                route('attendance.index'),
            ),
        );
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
