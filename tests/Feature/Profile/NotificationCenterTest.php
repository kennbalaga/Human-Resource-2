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
        $this->enableInAppScheduleUpdates($user);
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
        $this->enableInAppScheduleUpdates($user);
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
        $this->enableInAppScheduleUpdates($user);
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
        $this->enableInAppScheduleUpdates($owner);
        $this->storeScheduleNotification($owner);
        $notification = $owner->unreadNotifications()->firstOrFail();

        $this->actingAs($otherUser)
            ->get(route('notifications.open', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
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

    private function enableInAppScheduleUpdates(User $user): void
    {
        $user->preference()->updateOrCreate([], [
            'timezone' => 'Asia/Manila',
            'theme' => 'system',
            'email_notifications' => false,
            'attendance_reminders' => true,
            'schedule_updates' => true,
            'leave_updates' => true,
            'compact_navigation' => false,
            'reduce_motion' => false,
        ]);
        $user->unsetRelation('preference');
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
