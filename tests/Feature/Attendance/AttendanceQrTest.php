<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Attendance\AttendanceQrService;
use App\Support\Qr\QrEncoder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The QR badge standing in for the fingerprint terminal: who it identifies, who
 * may scan it, and what a scan writes.
 */
class AttendanceQrTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceQrService $codes;

    private User $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // Several tests scan, travel a few minutes, and scan again, expecting the
        // same attendance day. Run near midnight in the office timezone and those
        // minutes cross into tomorrow, where a "third scan" is a fresh time-in.
        // CI hit exactly that, so the clock is pinned to mid-morning.
        $office = OfficeLocation::query()->where('is_active', true)->firstOrFail();
        $this->travelTo(Carbon::now($office->timezone)->setTime(10, 0));

        $this->codes = app(AttendanceQrService::class);
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail()->employee;
    }

    public function test_a_code_resolves_to_the_employee_it_was_issued_to(): void
    {
        $resolved = $this->codes->resolve($this->codes->payloadFor($this->employee));

        $this->assertSame($this->employee->id, $resolved->id);
    }

    public function test_a_code_cannot_be_made_up_from_an_employee_id_alone(): void
    {
        $forged = [
            'HRMS-ATT1.'.$this->employee->id.'.0000000000000000000',
            (string) $this->employee->id,
            $this->employee->employee_number,
            'HRMS-ATT1.'.$this->employee->id,
            'not-a-code',
        ];

        foreach ($forged as $payload) {
            try {
                $this->codes->resolve($payload);
                $this->fail("[{$payload}] should not have resolved to an employee.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('qr_payload', $exception->errors());
            }
        }
    }

    public function test_one_employees_code_never_resolves_to_another(): void
    {
        $other = Employee::query()->whereKeyNot($this->employee->id)->firstOrFail();
        $payload = $this->codes->payloadFor($this->employee);
        $swapped = str_replace(
            '.'.$this->employee->id.'.',
            '.'.$other->id.'.',
            $payload,
        );

        $this->expectException(ValidationException::class);
        $this->codes->resolve($swapped);
    }

    public function test_issuing_a_new_code_retires_every_copy_of_the_old_one(): void
    {
        $old = $this->codes->payloadFor($this->employee);
        $this->codes->regenerate($this->employee);
        $new = $this->codes->payloadFor($this->employee->refresh());

        $this->assertNotSame($old, $new);
        $this->assertSame($this->employee->id, $this->codes->resolve($new)->id);

        $this->expectException(ValidationException::class);
        $this->codes->resolve($old);
    }

    /**
     * The bug this guards against: badges were signed with APP_KEY, which is
     * generated per installation and never shared. Two machines working off one
     * database therefore disagreed about a person's badge, so a code downloaded
     * on the office PC was refused by the scanner on the laptop. The signing
     * secret belongs to the employee record, not to whichever copy of the app
     * happens to be serving the page.
     */
    public function test_a_badge_does_not_depend_on_which_installation_issued_it(): void
    {
        $issuedHere = $this->codes->payloadFor($this->employee);

        // Same database row, a different machine's application key.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $service = app(AttendanceQrService::class);

        $this->assertSame($issuedHere, $service->payloadFor($this->employee->refresh()));
        $this->assertSame($this->employee->id, $service->resolve($issuedHere)->id);
    }

    public function test_a_badge_is_issued_to_an_employee_who_has_never_had_one(): void
    {
        $this->employee->forceFill(['attendance_qr_secret' => null])->save();

        $payload = $this->codes->payloadFor($this->employee->refresh());

        $this->assertSame($this->employee->id, $this->codes->resolve($payload)->id);
        $this->assertNotEmpty($this->employee->refresh()->attendance_qr_secret);
    }

    public function test_the_signing_secret_never_leaves_the_server(): void
    {
        $this->assertArrayNotHasKey('attendance_qr_secret', $this->employee->toArray());
    }

    public function test_scanning_records_time_in_then_time_out_for_the_badge_holder(): void
    {
        $payload = $this->codes->payloadFor($this->employee);

        $checkIn = $this->actingAs($this->manager)
            ->postJson(route('attendance.qr-scan.store'), ['payload' => $payload])
            ->assertOk();

        $this->assertSame('check-in', $checkIn->json('action'));
        $this->assertSame($this->employee->full_name, $checkIn->json('employee.name'));
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'check_in_method' => 'qr',
        ]);

        // Past the repeat-scan window, the same badge is the departure.
        $this->travel(2)->minutes();

        $checkOut = $this->actingAs($this->manager)
            ->postJson(route('attendance.qr-scan.store'), ['payload' => $payload])
            ->assertOk();

        $this->assertSame('check-out', $checkOut->json('action'));
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'check_out_method' => 'qr',
        ]);
    }

    public function test_a_badge_held_in_front_of_the_camera_is_not_read_as_a_second_scan(): void
    {
        $payload = $this->codes->payloadFor($this->employee);

        $this->actingAs($this->manager)
            ->postJson(route('attendance.qr-scan.store'), ['payload' => $payload])
            ->assertOk();

        $repeat = $this->actingAs($this->manager)
            ->postJson(route('attendance.qr-scan.store'), ['payload' => $payload])
            ->assertOk();

        $this->assertTrue($repeat->json('repeat'));
        $this->assertSame('check-in', $repeat->json('action'));
        $this->assertNull(
            AttendanceRecord::query()->where('employee_id', $this->employee->id)->value('check_out_at'),
        );

        // The operator has no way to tell "already recorded" from "stuck"
        // without being told exactly when a real scan will go through.
        $this->assertNotNull($repeat->json('retry_at'));
    }

    public function test_a_third_scan_is_refused_and_names_the_employee(): void
    {
        $payload = $this->codes->payloadFor($this->employee);

        $this->actingAs($this->manager)->postJson(route('attendance.qr-scan.store'), ['payload' => $payload]);
        $this->travel(2)->minutes();
        $this->actingAs($this->manager)->postJson(route('attendance.qr-scan.store'), ['payload' => $payload]);
        $this->travel(2)->minutes();

        $this->actingAs($this->manager)
            ->postJson(route('attendance.qr-scan.store'), ['payload' => $payload])
            ->assertStatus(422)
            ->assertJsonPath('errors.qr_payload.0', $this->employee->full_name.' has already completed attendance for today.');
    }

    public function test_an_ordinary_employee_cannot_scan_anyone_in(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $colleague = Employee::query()->whereKeyNot($this->employee->id)->firstOrFail();

        $this->actingAs($staff)
            ->postJson(route('attendance.qr-scan.store'), [
                'payload' => $this->codes->payloadFor($colleague),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $colleague->id,
            'check_in_method' => 'qr',
        ]);
    }

    public function test_the_scanner_view_is_rendered_for_staff_who_may_record_attendance(): void
    {
        $this->actingAs($this->manager)->get('/attendance')
            ->assertOk()
            ->assertSee('Badge scanner');

        $this->actingAs($this->manager)->get('/attendance?view=scanner')
            ->assertOk()
            ->assertSee('data-qr-scanner', false)
            ->assertSee('Scans this session');
    }

    /**
     * An officer at the entrance and an employee checking their own week are
     * doing different jobs, so the camera and the personal record never share
     * a screen.
     */
    public function test_the_personal_record_and_the_scanner_are_separate_views(): void
    {
        $this->actingAs($this->manager)->get('/attendance')
            ->assertOk()
            ->assertSee('data-attendance-live', false)
            ->assertSee('attendance-history-panel', false)
            ->assertDontSee('data-qr-scanner', false);

        $this->actingAs($this->manager)->get('/attendance?view=scanner')
            ->assertOk()
            ->assertSee('data-qr-scanner', false)
            ->assertDontSee('data-attendance-live', false);
    }

    /**
     * The scanner's decoder is the only third-party front-end dependency left,
     * so it loads as its own entry on the one page that reads a camera. Folding
     * it back into the shared bundle would put every button in the app behind a
     * package install; putting it on the profile page would put an employee's
     * own badge behind one.
     */
    public function test_only_the_scanner_page_carries_the_qr_script(): void
    {
        $this->assertTrue($this->loadsQrScript('/attendance'), 'The scanner page is missing its decoder.');

        foreach (['/profile', '/dashboard', '/schedules'] as $path) {
            $this->assertFalse($this->loadsQrScript($path), "{$path} should not load the QR decoder.");
        }
    }

    /**
     * Read the page's actual script tags. Matching on the raw HTML would count
     * the /profile/attendance-qr/download link as a script and quietly pass.
     */
    private function loadsQrScript(string $path): bool
    {
        $html = $this->actingAs($this->manager)->get($path)->assertOk()->getContent();
        preg_match_all('/<script[^>]+src="([^"]+)"/', $html, $matches);

        return collect($matches[1])->contains(fn (string $src) => str_contains($src, 'attendance-qr'));
    }

    public function test_a_badge_is_drawn_by_the_server_and_needs_no_scripts(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $response = $this->actingAs($staff)->get('/profile')->assertOk();

        // The code itself, in the markup, not a canvas waiting to be painted.
        $response->assertSee('My attendance QR')
            ->assertSee('<svg xmlns="http://www.w3.org/2000/svg"', false)
            ->assertDontSee('data-qr-canvas', false);
    }

    public function test_a_badge_downloads_as_a_readable_image(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $response = $this->actingAs($staff)
            ->get(route('profile.attendance-qr.download'))
            ->assertOk()
            ->assertHeader('Content-Type', extension_loaded('gd') ? 'image/png' : 'image/svg+xml');

        $this->assertStringContainsString(
            'attendance-qr-'.$staff->employee->employee_number,
            $response->headers->get('Content-Disposition'),
        );

        $body = $response->getContent();
        $this->assertNotEmpty($body);
        $this->assertStringStartsWith(
            extension_loaded('gd') ? "\x89PNG" : '<svg',
            $body,
            'The download is not the image type its headers promise.',
        );
    }

    public function test_the_scanner_is_hidden_from_an_ordinary_employee(): void
    {
        // Asking for the scanner by URL falls back to the employee's own view.
        $this->actingAs(User::query()->where('email', 'employee@hrms.local')->firstOrFail())
            ->get('/attendance?view=scanner')
            ->assertOk()
            ->assertDontSee('data-qr-scanner', false)
            ->assertDontSee('Badge scanner')
            ->assertSee('Recent attendance');
    }

    public function test_qr_records_can_be_filtered_and_read_back_in_the_reports(): void
    {
        $this->actingAs($this->manager)->postJson(route('attendance.qr-scan.store'), [
            'payload' => $this->codes->payloadFor($this->employee),
        ])->assertOk();

        $this->actingAs($this->manager)
            ->get(route('attendance.reports.index', ['capture_method' => 'qr']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertSee($this->employee->full_name)
            ->assertSee('QR badge');
    }

    public function test_an_employee_sees_a_code_carrying_their_own_payload(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($staff)->get('/profile')->assertOk()->assertSee('My attendance QR');

        // The payload is no longer written into the page as text — it exists
        // only as the drawn code — so the grid itself is what identifies them.
        $this->assertSame(
            QrEncoder::matrix($this->codes->payloadFor($staff->employee)),
            QrEncoder::matrix($this->codes->payloadFor($staff->employee->refresh())),
        );
        $this->assertNotSame(
            QrEncoder::matrix($this->codes->payloadFor($staff->employee)),
            QrEncoder::matrix($this->codes->payloadFor($this->manager->employee)),
        );
    }

    public function test_an_employee_cannot_retire_their_own_badge_by_accident(): void
    {
        // The self-service button is deliberately gone: a stray click would kill
        // a badge the holder had already printed, with no way to undo it.
        $this->assertFalse(app('router')->has('profile.attendance-qr.regenerate'));
    }

    public function test_hr_can_retire_a_lost_badge_from_the_employee_record(): void
    {
        $lost = $this->codes->payloadFor($this->employee);

        $this->actingAs($this->manager)->get(route('employees.show', [$this->employee, 'panel' => 1]))
            ->assertOk()
            ->assertSee('Attendance badge')
            ->assertSee('Issue new badge');

        $this->actingAs($this->manager)
            ->post(route('employees.attendance-qr.reissue', $this->employee))
            ->assertRedirect(route('employees.index', ['employee' => $this->employee->id]))
            ->assertSessionHasNoErrors();

        // The badge in someone's wallet stops working the moment HR reissues.
        $replacement = $this->codes->payloadFor($this->employee->refresh());
        $this->assertNotSame($lost, $replacement);
        $this->assertSame($this->employee->id, $this->codes->resolve($replacement)->id);

        $this->expectException(ValidationException::class);
        $this->codes->resolve($lost);
    }

    public function test_an_ordinary_employee_cannot_retire_anyone_else_s_badge(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $colleague = Employee::query()->whereKeyNot($this->employee->id)->firstOrFail();
        $before = $this->codes->payloadFor($colleague);

        $this->actingAs($staff)
            ->post(route('employees.attendance-qr.reissue', $colleague))
            ->assertForbidden();

        $this->assertSame($before, $this->codes->payloadFor($colleague->refresh()));
    }
}
