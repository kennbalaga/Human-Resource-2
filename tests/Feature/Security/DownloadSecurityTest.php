<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class DownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
    }

    public function test_a_download_asks_for_the_password_first(): void
    {
        $this->actingAs($this->employee)
            ->from(route('timesheets.index'))
            ->get(route('timesheets.export'))
            ->assertRedirect(route('password.confirm'));

        $this->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Confirm it’s you')
            ->assertSee('href="'.route('timesheets.index').'"', false);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'timesheets.export']);
    }

    public function test_a_page_with_a_download_carries_the_modal_and_marks_its_links(): void
    {
        $this->actingAs($this->employee)
            ->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee('data-download-confirm', false)
            ->assertSee('data-confirmed-until="0"', false)
            ->assertSee('data-download href="'.route('timesheets.export').'"', false);
    }

    public function test_the_page_is_told_when_the_confirmation_runs_out(): void
    {
        $confirmedAt = now()->unix();

        $this->actingAs($this->employee)
            ->withSession(['auth.password_confirmed_at' => $confirmedAt])
            ->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee('data-confirmed-until="'.($confirmedAt + config('security.downloads.password_timeout_seconds')).'"', false);
    }

    public function test_the_modal_confirms_without_leaving_the_page(): void
    {
        $this->actingAs($this->employee)
            ->postJson(route('password.confirm.store'), ['password' => 'ChangeMe123!'])
            ->assertNoContent();

        $this->get(route('timesheets.export'))->assertOk();
    }

    public function test_the_modal_reports_a_wrong_password_without_opening_downloads(): void
    {
        $this->actingAs($this->employee)
            ->postJson(route('password.confirm.store'), ['password' => 'not-the-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->get(route('timesheets.export'))->assertRedirect(route('password.confirm'));
    }

    public function test_the_confirmation_page_is_not_reachable_without_a_pending_download(): void
    {
        $this->actingAs($this->employee)
            ->get(route('password.confirm'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_wrong_password_does_not_open_downloads(): void
    {
        $this->actingAs($this->employee)->get(route('timesheets.export'));

        $this->from(route('password.confirm'))
            ->post(route('password.confirm.store'), ['password' => 'not-the-password'])
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHasErrors('password');

        $this->get(route('timesheets.export'))->assertRedirect(route('password.confirm'));
    }

    public function test_the_right_password_returns_to_the_page_and_starts_the_download(): void
    {
        $export = route('timesheets.export', ['date_from' => '2027-01-01', 'date_to' => '2027-01-31']);

        $this->actingAs($this->employee)
            ->from(route('timesheets.index'))
            ->get($export);

        $this->post(route('password.confirm.store'), ['password' => 'ChangeMe123!'])
            ->assertRedirect(route('timesheets.index'))
            ->assertSessionHas('download.start', $export);

        $this->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee('window.location.assign', false);

        $this->get($export)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_a_link_from_another_site_is_never_the_return_target(): void
    {
        $this->actingAs($this->employee)
            ->withHeader('referer', 'https://evil.example/phish')
            ->get(route('timesheets.export'));

        $this->post(route('password.confirm.store'), ['password' => 'ChangeMe123!'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_the_confirmation_expires(): void
    {
        $this->actingAs($this->employee)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->get(route('timesheets.export'))
            ->assertOk();

        $this->travel(config('security.downloads.password_timeout_seconds') + 1)->seconds();

        $this->get(route('timesheets.export'))->assertRedirect(route('password.confirm'));
    }

    public function test_every_file_route_asks_for_the_password(): void
    {
        $names = ['timesheets.export', 'analytics.export', 'audit-logs.export', 'leave-attachments.download',
            'profile.attendance-qr.download', 'payslips.download', 'reports.export', 'reports.print',
            'attendance.reports.export', 'attendance.reports.export-pdf', 'attendance.reports.export-excel'];

        foreach ($names as $name) {
            $this->assertContains('download.confirm', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_downloads_are_rate_limited_per_account(): void
    {
        RateLimiter::clear('downloads|'.$this->employee->id);
        $this->actingAs($this->employee)->withSession(['auth.password_confirmed_at' => now()->unix()]);

        for ($i = 0; $i < config('security.downloads.per_minute'); $i++) {
            $this->get(route('profile.attendance-qr.download'))->assertOk();
        }

        $this->get(route('profile.attendance-qr.download'))->assertTooManyRequests();
    }

    public function test_a_download_without_its_own_audit_is_recorded(): void
    {
        $this->actingAs($this->employee)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->get(route('profile.attendance-qr.download'))
            ->assertOk();

        $log = AuditLog::query()->where('action', 'profile.attendance-qr.download')->sole();
        $this->assertSame($this->employee->id, $log->user_id);
        $this->assertSame(200, $log->response_status);
        $this->assertSame('Data export', AuditActivity::activity($log)['label']);
    }
}
