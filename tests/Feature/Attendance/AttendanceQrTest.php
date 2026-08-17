<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceQrService;
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

    public function test_the_scanner_panel_is_rendered_for_staff_who_may_record_attendance(): void
    {
        $this->actingAs($this->manager)->get('/attendance')
            ->assertOk()
            ->assertSee('Scan employee QR');
    }

    public function test_the_scanner_panel_is_hidden_from_an_ordinary_employee(): void
    {
        $this->actingAs(User::query()->where('email', 'employee@hrms.local')->firstOrFail())
            ->get('/attendance')
            ->assertOk()
            ->assertDontSee('Scan employee QR');
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

    public function test_an_employee_sees_their_own_code_on_their_profile(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($staff)->get('/profile')
            ->assertOk()
            ->assertSee('My attendance QR')
            ->assertSee($this->codes->payloadFor($staff->employee), false);
    }

    public function test_an_employee_can_issue_themselves_a_new_code(): void
    {
        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $before = $staff->employee->attendance_qr_revision;

        $this->actingAs($staff)
            ->post(route('profile.attendance-qr.regenerate'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, $staff->employee->refresh()->attendance_qr_revision);
    }
}
