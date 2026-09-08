<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use App\Services\Organization\NotificationEmailSettings;
use App\Services\Security\SecurityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityAlertEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
    }

    public function test_an_administrator_reset_of_two_factor_reaches_the_user_by_email(): void
    {
        $administrator = $this->userForEmployee('SYS-ADMIN-2026-0001');
        $target = $this->userForEmployee('HR-MGR-2026-0001');

        $this->actingAs($administrator)->post(route('employees.two-factor.reset', $target->employee), [
            'current_password' => 'ChangeMe123!',
            'identity_verified' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $target->id,
            'data->category' => 'security',
        ]);

        Notification::assertSentTo(
            $target,
            SecurityAlertNotification::class,
            fn (SecurityAlertNotification $notification) => $notification->subject === 'Two-factor authentication reset',
        );
    }

    public function test_a_security_email_is_sent_even_when_notification_email_is_paused(): void
    {
        $user = $this->userForEmployee('HR-MGR-2026-0001');
        // Notification email paused for the whole system: the one setting that
        // now governs every other kind of notification mail.
        app(NotificationEmailSettings::class)->update($this->userForEmployee('SYS-ADMIN-2026-0001'), false);

        app(SecurityAlertService::class)->alertUser(
            $user,
            'Sign-in from a new device',
            'Somebody signed in to your account from a device you have not used before.',
            'Review audit logs',
            url('/audit-logs'),
            'danger',
            'auth.new_device',
        );

        Notification::assertSentTo($user, SecurityAlertNotification::class);
    }

    public function test_an_administrator_alert_is_stored_and_emailed(): void
    {
        $administrator = $this->userForEmployee('SYS-ADMIN-2026-0001');

        app(SecurityAlertService::class)->alertAdministrators(
            'attachment.scan_failed',
            'Attachment scanning is unavailable',
            'The virus scanner could not be reached, so uploads are being rejected.',
            'critical',
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $administrator->id,
            'data->category' => 'security',
            'data->security_event' => 'attachment.scan_failed',
        ]);

        Notification::assertSentTo($administrator, SecurityAlertNotification::class);
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()->with('employee')
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
