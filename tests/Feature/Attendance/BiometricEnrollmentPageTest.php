<?php

namespace Tests\Feature\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfirmsDownloadPassword;
use Tests\TestCase;

class BiometricEnrollmentPageTest extends TestCase
{
    use ConfirmsDownloadPassword;
    use RefreshDatabase;

    private const SERIAL = 'QME2261300147';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_only_a_system_administrator_can_open_the_roster_or_its_export(): void
    {
        $this->device();

        foreach (['hr.manager@hrms.local', 'employee@hrms.local'] as $email) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            // Flushed between accounts: this app authenticates sessions and
            // allows one active session per account, so signing a second user
            // in on a session the first one owns logs them straight back out
            // and the assertion reads 302-to-login instead of the 403 it is
            // about.
            $this->flushSession();
            $this->actingAs($user)->get(route('settings.biometric-terminals.index'))->assertForbidden();

            $this->flushSession();
            $this->actingAs($user)->get(route('settings.biometric-terminals.export'))->assertForbidden();
        }

        $this->flushSession();
        $this->actingAs($this->admin())->get(route('settings.biometric-terminals.index'))->assertOk();
    }

    public function test_the_roster_lists_an_employee_who_has_no_enrollment_yet(): void
    {
        $this->device();
        $employee = $this->activeEmployee();

        // The load-bearing case: the page is driven from employees, not from
        // enrolments, because "who still needs enrolling" is the actual
        // question. A query over biometric_enrollments cannot answer it.
        $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.index'))
            ->assertOk()
            ->assertSee($employee->full_name)
            ->assertSee('No PIN yet');
    }

    public function test_the_page_asks_for_a_terminal_before_it_offers_a_roster(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.index'))
            ->assertOk()
            ->assertSee('No terminal is registered yet')
            ->assertSee('Register a terminal above');
    }

    public function test_registering_the_real_terminal_makes_the_punch_endpoint_accept_it(): void
    {
        config(['attendance.biometric_bridge.secret' => 'bridge-test-secret']);

        $payload = [
            'device_sn' => self::SERIAL,
            'sent_at' => now()->toIso8601String(),
            'punches' => [[
                'fingerprint' => hash('sha256', 'commissioning'),
                'pin' => '1',
                'punched_at' => now()->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
                'punch_code' => 0,
                'verify_mode' => 1,
            ]],
        ];
        $headers = [
            'X-Bridge-Signature' => hash_hmac('sha256', json_encode($payload), 'bridge-test-secret'),
            'X-Bridge-Device' => self::SERIAL,
        ];

        // Before: the serial is not on the allowlist.
        $this->postJson('/api/biometric/punches', $payload, $headers)->assertStatus(422);

        $this->actingAs($this->admin())->post(route('settings.biometric-devices.store'), [
            'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
            'code' => 'djnrmhs-main-entrance',
            'name' => 'Main Entrance Terminal',
            'provider' => 'zkteco',
            'serial_number' => self::SERIAL,
        ])->assertRedirect();

        // The bridge agent is a Python process with no cookies, so the punch
        // goes in unauthenticated. The session has to be dropped to model that:
        // left signed in as the administrator, EnforceReadOnlyRole refuses the
        // POST, because a system administrator is a read-only role and the
        // punch route is not in its allowlist. Harmless in production — nothing
        // authenticates to that endpoint — but it would make this test lie
        // about what the bridge sees.
        $this->flushSession();
        // flushSession alone is not enough: actingAs() also sets the user on
        // the guard for the rest of the test, so the guards have to be dropped
        // as well for the request to arrive genuinely unauthenticated.
        app('auth')->forgetGuards();

        // Accepted. This is the proof the feature solved the stated problem:
        // commissioning a terminal no longer needs hand-written SQL.
        $this->postJson('/api/biometric/punches', $payload, $headers)->assertAccepted();
    }

    public function test_a_duplicate_serial_is_refused(): void
    {
        $this->device();

        $this->actingAs($this->admin())->post(route('settings.biometric-devices.store'), [
            'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
            'code' => 'a-different-code',
            'name' => 'Second Terminal',
            'provider' => 'zkteco',
            'serial_number' => self::SERIAL,
        ])->assertSessionHasErrors('serial_number');
    }

    public function test_a_serial_pasted_with_whitespace_is_trimmed(): void
    {
        $this->actingAs($this->admin())->post(route('settings.biometric-devices.store'), [
            'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
            'code' => 'djnrmhs-main-entrance',
            'name' => 'Main Entrance Terminal',
            'provider' => 'zkteco',
            'serial_number' => '  '.self::SERIAL.' ',
        ])->assertRedirect();

        $this->assertDatabaseHas('biometric_devices', ['serial_number' => self::SERIAL]);
    }

    public function test_an_administrator_reserves_one_pin_from_the_roster(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $this->actingAs($this->admin())->post(route('settings.biometric-enrollments.store'), [
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('biometric_enrollments', [
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'enrolled_at' => null,
        ]);
    }

    public function test_bulk_assign_requires_the_confirmation_flag(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())->post(route('settings.biometric-enrollments.bulk'), [
            'biometric_device_id' => $device->id,
        ])->assertSessionHasErrors('confirm');

        $this->assertDatabaseCount('biometric_enrollments', 0);
    }

    public function test_bulk_assign_reserves_a_pin_for_every_active_employee(): void
    {
        $device = $this->device();
        $expected = Employee::query()->where('employment_status', 'active')->notArchived()->count();

        $this->actingAs($this->admin())->post(route('settings.biometric-enrollments.bulk'), [
            'biometric_device_id' => $device->id,
            'confirm' => 1,
        ])->assertRedirect();

        $this->assertDatabaseCount('biometric_enrollments', $expected);
    }

    public function test_the_roster_filters_narrow_the_result_set(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $all = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.index', ['device' => $device->id]))
            ->viewData('roster')->total();

        $narrowed = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.index', [
                'device' => $device->id,
                'search' => $employee->last_name,
            ]))
            ->viewData('roster')->total();

        $this->assertLessThanOrEqual($all, $narrowed);
        $this->assertGreaterThan(0, $narrowed);

        // Nobody is captured yet, so that state filter must come back empty
        // even though the unfiltered roster is not.
        $this->assertSame(
            0,
            $this->actingAs($this->admin())
                ->get(route('settings.biometric-terminals.index', ['device' => $device->id, 'state' => 'captured']))
                ->viewData('roster')->total(),
        );
    }

    public function test_the_summary_counts_the_whole_filtered_set_not_the_visible_page(): void
    {
        $device = $this->device();

        $response = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.index', ['device' => $device->id]));

        $summary = $response->viewData('summary');
        $roster = $response->viewData('roster');

        $this->assertSame($roster->total(), $summary['people']);
        $this->assertSame($summary['people'], $summary['unassigned']);
    }

    public function test_an_employee_whose_template_is_marked_captured_reads_as_captured(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $enrollment = BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patch(route('settings.biometric-enrollments.update', $enrollment), ['action' => 'capture'])
            ->assertRedirect();

        $this->assertNotNull($enrollment->refresh()->enrolled_at);

        // And it can be released again for a re-capture, keeping the PIN.
        $this->actingAs($this->admin())
            ->patch(route('settings.biometric-enrollments.update', $enrollment), ['action' => 'release'])
            ->assertRedirect();

        $this->assertNull($enrollment->refresh()->enrolled_at);
        $this->assertSame((string) $employee->id, $enrollment->refresh()->external_user_id);
    }

    public function test_departed_staff_with_a_captured_template_are_surfaced_for_removal(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);
        $employee->forceFill(['employment_status' => 'terminated'])->save();

        $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.index', ['device' => $device->id]))
            ->assertOk()
            ->assertSee('Templates still on the terminal')
            ->assertSee('does not reach the terminal', false);
    }

    public function test_the_roster_export_carries_the_pin_the_name_and_the_department(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $response = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.export', ['device' => $device->id]));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Device PIN', $csv);
        $this->assertStringContainsString('Enrolled By (sign here)', $csv);
        $this->assertStringContainsString($employee->last_name, $csv);
        $this->assertStringContainsString((string) $employee->id, $csv);
    }

    public function test_the_zktime_export_names_its_columns_as_zktime_names_its_fields(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'is_active' => true,
        ]);

        $csv = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.export', ['device' => $device->id, 'format' => 'zktime']))
            ->assertOk()
            ->streamedContent();

        // The wizard asks you to map source columns onto ZKTime's own fields,
        // so the headers have to read the way that dialog reads.
        $this->assertStringContainsString('AC No.', $csv);
        $this->assertStringContainsString('Name', $csv);
        $this->assertStringContainsString('No.', $csv);
        $this->assertStringContainsString('Title', $csv);

        // One Name column, not the roster's two: ZKTime holds a single field.
        $this->assertStringContainsString($employee->last_name.', '.$employee->first_name, $csv);
        $this->assertStringNotContainsString('Enrolled By (sign here)', $csv);

        // AC No. is the device PIN, and is the one column that must be right.
        $this->assertStringContainsString((string) $employee->id, $csv);
    }

    public function test_the_zktime_export_leaves_out_anyone_with_no_pin_reserved(): void
    {
        $device = $this->device();
        $withPin = $this->activeEmployee();
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $withPin->id,
            'external_user_id' => (string) $withPin->id,
            'is_active' => true,
        ]);

        $without = Employee::query()
            ->where('employment_status', 'active')
            ->notArchived()
            ->whereKeyNot($withPin->id)
            ->firstOrFail();

        $csv = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.export', ['device' => $device->id, 'format' => 'zktime']))
            ->assertOk()
            ->streamedContent();

        // A terminal must not hold a user this roster has not reserved a PIN
        // for: their punches would arrive unmatched with nothing to explain it.
        $this->assertStringContainsString($withPin->last_name.', '.$withPin->first_name, $csv);
        $this->assertStringNotContainsString($without->last_name.', '.$without->first_name, $csv);

        // The human sheet still lists everybody, including the unreserved.
        $sheet = $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.export', ['device' => $device->id]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($without->last_name, $sheet);
    }

    public function test_an_unknown_export_format_is_refused(): void
    {
        $this->device();

        // A redirect back with the error, not a 422: this is a browser GET on
        // a web route, and that is what the audit log export does with a bad
        // filter too.
        $this->actingAs($this->admin())
            ->get(route('settings.biometric-terminals.export', ['format' => 'nonsense']))
            ->assertRedirect()
            ->assertSessionHasErrors('format');
    }

    public function test_the_roster_export_is_wired_to_the_audited_download_stack(): void
    {
        // Asserted at the route rather than by exercising it, because
        // ConfirmsDownloadPassword switches that middleware off for this whole
        // class and DownloadSecurityTest already covers how the confirmation
        // behaves. What can actually go wrong here is forgetting to put the
        // stack on the route at all, and this is the assertion that catches it.
        //
        // The roster is a personnel directory joined to the credentials that
        // open the attendance record, so it gets password re-entry, a rate
        // limit and an audit row like every other file this app hands out.
        $middleware = app('router')->getRoutes()
            ->getByName('settings.biometric-terminals.export')
            ->gatherMiddleware();

        $this->assertContains('download.confirm', $middleware);
        $this->assertContains('download.audit', $middleware);
        $this->assertContains('throttle:downloads', $middleware);
    }

    public function test_the_operational_tools_panel_links_to_the_roster(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Biometric terminals')
            ->assertSee(route('settings.biometric-terminals.index'), false);
    }

    private function admin(): User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('slug', 'system-administrator'))
            ->firstOrFail();
    }

    private function device(): BiometricDevice
    {
        return BiometricDevice::query()->create([
            'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
            'code' => 'djnrmhs-main-entrance',
            'name' => 'Main Entrance Terminal',
            'provider' => 'zkteco',
            'serial_number' => self::SERIAL,
            'is_active' => true,
        ]);
    }

    private function activeEmployee(): Employee
    {
        return Employee::query()->where('employment_status', 'active')->notArchived()->firstOrFail();
    }
}
