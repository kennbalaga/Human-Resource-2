<?php

namespace Tests\Feature\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BiometricBridgePunchTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'bridge-test-secret';

    private const SERIAL = 'QME2261300147';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        Config::set('attendance.biometric_bridge.secret', self::SECRET);
    }

    public function test_a_signed_batch_records_attendance_through_the_gateway(): void
    {
        $employee = $this->enrolledEmployee('1001');

        $this->postBatch([$this->punch('a', '1001', 0)])
            ->assertAccepted()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('results.0.status', 'processed');

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('a'),
            'device_sn' => self::SERIAL,
            'pin' => '1001',
            'employee_id' => $employee->id,
            'processing_status' => 'processed',
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'check_in_method' => 'biometric',
        ]);
    }

    public function test_the_device_wall_clock_is_read_as_office_local_time(): void
    {
        $employee = $this->enrolledEmployee('1001');

        // The terminal reports the time on its own face with no timezone. Read
        // as Manila (UTC+8), 07:02 local is 23:02 UTC the previous day; read as
        // UTC it would be stored eight hours adrift and land the punch on the
        // wrong attendance date.
        $this->postBatch([[
            'fingerprint' => $this->digest('tz'),
            'pin' => '1001',
            'punched_at' => '2026-10-01 07:02:11',
            'punch_code' => 0,
            'verify_mode' => 1,
        ]])->assertAccepted();

        $punch = BiometricPunch::query()->where('pin', '1001')->sole();

        $this->assertSame('2026-09-30 23:02:11', $punch->punched_at->toDateTimeString());
        $this->assertSame($employee->id, $punch->employee_id);
    }

    public function test_a_resent_batch_is_a_no_op_rather_than_a_second_record(): void
    {
        $this->enrolledEmployee('1001');

        $batch = [$this->punch('dup', '1001', 0)];

        $this->postBatch($batch)->assertAccepted()->assertJsonPath('results.0.status', 'processed');

        // The bridge resends when a response is lost in transit. The second
        // delivery must change nothing.
        $this->postBatch($batch)->assertAccepted()->assertJsonPath('results.0.status', 'processed');

        $this->assertDatabaseCount('biometric_punches', 1);
        $this->assertDatabaseCount('biometric_scan_events', 1);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_an_unsigned_or_mis_signed_batch_is_rejected(): void
    {
        $this->enrolledEmployee('1001');

        $payload = $this->payload([$this->punch('x', '1001', 0)]);

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Device' => self::SERIAL,
        ])->assertUnauthorized();

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => str_repeat('0', 64),
            'X-Bridge-Device' => self::SERIAL,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('biometric_punches', 0);
    }

    public function test_a_tampered_body_fails_the_signature(): void
    {
        $this->enrolledEmployee('1001');

        $signed = $this->payload([$this->punch('x', '1001', 0)]);
        $tampered = $this->payload([$this->punch('x', '9999', 0)]);

        // Signed for one PIN, delivered with another: the signature covers the
        // punches themselves, not merely the caller.
        $this->call('POST', '/api/biometric/punches', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BRIDGE_SIGNATURE' => $this->sign(json_encode($signed)),
            'HTTP_X_BRIDGE_DEVICE' => self::SERIAL,
        ], json_encode($tampered))->assertUnauthorized();

        $this->assertDatabaseCount('biometric_punches', 0);
    }

    public function test_an_unconfigured_secret_refuses_the_request_rather_than_accepting_it(): void
    {
        Config::set('attendance.biometric_bridge.secret', null);
        $this->enrolledEmployee('1001');

        $payload = $this->payload([$this->punch('x', '1001', 0)]);

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => hash_hmac('sha256', json_encode($payload), ''),
            'X-Bridge-Device' => self::SERIAL,
        ])->assertStatus(503);

        $this->assertDatabaseCount('biometric_punches', 0);
    }

    public function test_an_unregistered_device_serial_is_refused(): void
    {
        $this->enrolledEmployee('1001');

        $payload = $this->payload([$this->punch('x', '1001', 0)], 'NOT-OUR-TERMINAL');

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => $this->sign(json_encode($payload)),
            'X-Bridge-Device' => 'NOT-OUR-TERMINAL',
        ])->assertStatus(422)->assertJsonValidationErrors('device_sn');

        $this->assertDatabaseCount('biometric_punches', 0);
    }

    public function test_a_serial_that_differs_from_the_signing_bridge_is_refused(): void
    {
        $this->enrolledEmployee('1001');
        $payload = $this->payload([$this->punch('x', '1001', 0)]);

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => $this->sign(json_encode($payload)),
            'X-Bridge-Device' => 'SOME-OTHER-TERMINAL',
        ])->assertStatus(422)->assertJsonValidationErrors('device_sn');
    }

    public function test_a_malformed_payload_is_rejected_field_by_field(): void
    {
        $this->enrolledEmployee('1001');

        $payload = [
            'device_sn' => self::SERIAL,
            'sent_at' => 'not-a-date',
            'punches' => [[
                'fingerprint' => 'too-short',
                'pin' => '',
                'punched_at' => 'whenever',
                'punch_code' => 900,
            ]],
        ];

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => $this->sign(json_encode($payload)),
            'X-Bridge-Device' => self::SERIAL,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'sent_at',
            'punches.0.fingerprint',
            'punches.0.pin',
            'punches.0.punched_at',
            'punches.0.punch_code',
        ]);
    }

    public function test_an_unmapped_punch_code_is_stored_without_guessing_a_direction(): void
    {
        $employee = $this->enrolledEmployee('1001');

        // Punch code 2 (break out) has no attendance direction configured. The
        // scan is real evidence and is kept, but nothing is derived from it.
        $this->postBatch([$this->punch('break', '1001', 2)])
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'unsupported');

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('break'),
            'punch_code' => 2,
            'processing_status' => 'unsupported',
        ]);

        $this->assertDatabaseMissing('attendance_records', ['employee_id' => $employee->id]);
    }

    public function test_an_unenrolled_pin_is_kept_as_evidence_and_reported_unmatched(): void
    {
        $this->enrolledEmployee('1001');

        $this->postBatch([$this->punch('stranger', '7777', 0)])
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'unmatched');

        // Stored with no employee: a punch by someone enrolled on the device but
        // not yet mapped here is not attendance, and it is not nothing either.
        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('stranger'),
            'pin' => '7777',
            'employee_id' => null,
            'processing_status' => 'unmatched',
        ]);
    }

    public function test_a_punch_older_than_the_drift_window_is_not_turned_into_attendance(): void
    {
        $employee = $this->enrolledEmployee('1001');
        Config::set('attendance.biometric_bridge.max_punch_age_hours', 24);

        $this->postBatch([[
            'fingerprint' => $this->digest('drift'),
            'pin' => '1001',
            'punched_at' => now()->timezone('Asia/Manila')->subDays(5)->format('Y-m-d H:i:s'),
            'punch_code' => 0,
            'verify_mode' => 1,
        ]])->assertAccepted()->assertJsonPath('results.0.status', 'stale');

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('drift'),
            'processing_status' => 'stale',
        ]);
        $this->assertDatabaseMissing('attendance_records', ['employee_id' => $employee->id]);
    }

    public function test_no_biometric_template_is_ever_stored(): void
    {
        $this->enrolledEmployee('1001');

        $payload = $this->payload([[
            'fingerprint' => $this->digest('a'),
            'pin' => '1001',
            'punched_at' => '2026-10-01 07:02:11',
            'punch_code' => 0,
            'verify_mode' => 1,
            // A bridge that sent a template anyway must not get it persisted.
            'template' => base64_encode('pretend-fingerprint-template'),
        ]]);

        $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => $this->sign(json_encode($payload)),
            'X-Bridge-Device' => self::SERIAL,
        ])->assertAccepted();

        $columns = array_keys(BiometricPunch::query()->sole()->getAttributes());

        $this->assertNotContains('template', $columns);
        $this->assertSame(
            0,
            BiometricPunch::query()->where('pin', 'like', '%template%')->count(),
        );
    }

    public function test_punch_batches_do_not_fill_the_business_audit_log(): void
    {
        $this->enrolledEmployee('1001');

        $this->postBatch([$this->punch('a', '1001', 0)])->assertAccepted();

        $this->assertDatabaseMissing('audit_logs', ['route_name' => 'api.biometric.punches.store']);
    }

    // --- helpers ---------------------------------------------------------

    private function enrolledEmployee(string $pin): Employee
    {
        $office = OfficeLocation::query()->where('is_active', true)->firstOrFail();

        $employee = User::query()
            ->whereHas('employee', fn ($query) => $query->where('employment_status', 'active'))
            ->firstOrFail()
            ->employee;

        $device = BiometricDevice::query()->create([
            'office_location_id' => $office->id,
            'code' => 'djnrmhs-main-entrance',
            'name' => 'Main Entrance Terminal',
            'provider' => 'zkteco',
            'serial_number' => self::SERIAL,
            'is_active' => true,
        ]);

        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => $pin,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        return $employee;
    }

    /** @return array<string, mixed> */
    private function punch(string $seed, string $pin, int $punchCode): array
    {
        return [
            'fingerprint' => $this->digest($seed),
            'pin' => $pin,
            'punched_at' => now()->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'punch_code' => $punchCode,
            'verify_mode' => 1,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $punches
     * @return array<string, mixed>
     */
    private function payload(array $punches, ?string $serial = null): array
    {
        return [
            'device_sn' => $serial ?? self::SERIAL,
            'sent_at' => now()->toIso8601String(),
            'punches' => $punches,
        ];
    }

    /** @param array<int, array<string, mixed>> $punches */
    private function postBatch(array $punches): TestResponse
    {
        $payload = $this->payload($punches);

        return $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => $this->sign(json_encode($payload)),
            'X-Bridge-Device' => self::SERIAL,
        ]);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }

    private function digest(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
