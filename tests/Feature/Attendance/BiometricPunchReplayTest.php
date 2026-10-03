<?php

namespace Tests\Feature\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricPunch;
use App\Models\BiometricScanEvent;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BiometricPunchReplayTest extends TestCase
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

    public function test_a_punch_that_arrived_before_the_enrollment_existed_is_recovered(): void
    {
        // The ward-by-ward rollout case: somebody scans before their enrolment
        // has been created here, so the punch is stored with nobody to put it
        // against.
        $device = $this->device();
        $employee = $this->activeEmployee();

        $this->postPunch('early', (string) $employee->id, 0)
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'unmatched');

        $this->assertDatabaseMissing('attendance_records', ['employee_id' => $employee->id]);

        $this->enroll($device, $employee);

        $this->artisan('biometric:replay')->assertSuccessful();

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('early'),
            'processing_status' => 'processed',
            'employee_id' => $employee->id,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'check_in_method' => 'biometric',
        ]);
    }

    public function test_replaying_an_unmatched_punch_reprocesses_it_rather_than_short_circuiting_in_the_gateway(): void
    {
        // Regression guard. BiometricAttendanceGateway::receive() returns early
        // unless the scan event is still 'received', so an unmatched punch owns
        // an event stamped 'unmatched'. Without reopening that event the replay
        // reports the same failure while doing no work at all -- a no-op that
        // looks like a run.
        $device = $this->device();
        $employee = $this->activeEmployee();

        $this->postPunch('guard', (string) $employee->id, 0)->assertAccepted();

        $punch = BiometricPunch::query()->where('fingerprint', $this->digest('guard'))->sole();
        $this->assertNotNull($punch->biometric_scan_event_id, 'An unmatched punch should own a scan event.');
        $this->assertSame('unmatched', BiometricScanEvent::query()->findOrFail($punch->biometric_scan_event_id)->status);

        $this->enroll($device, $employee);
        $this->artisan('biometric:replay')->assertSuccessful();

        $this->assertSame(
            'processed',
            BiometricScanEvent::query()->findOrFail($punch->biometric_scan_event_id)->status,
        );
        // Reopened, not duplicated: the same event carried through.
        $this->assertDatabaseCount('biometric_scan_events', 1);
    }

    public function test_a_corrected_punch_code_mapping_recovers_the_run_it_had_rejected(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $this->enroll($device, $employee);

        // Code 2 is unmapped, so the scan is kept but no direction is guessed.
        $this->postPunch('code', (string) $employee->id, 2)
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'unsupported');

        // What the operator does after watching the real unit: correct the map.
        Config::set('attendance.biometric_bridge.punch_codes', [0 => 'check_in', 1 => 'check_out', 2 => 'check_in']);

        $this->artisan('biometric:replay')->assertSuccessful();

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('code'),
            'processing_status' => 'processed',
        ]);
        $this->assertDatabaseHas('attendance_records', ['employee_id' => $employee->id]);
    }

    public function test_stale_punches_are_left_alone_unless_they_are_named(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $this->enroll($device, $employee);
        Config::set('attendance.biometric_bridge.max_punch_age_hours', 24);

        $this->postRaw([[
            'fingerprint' => $this->digest('old'),
            'pin' => (string) $employee->id,
            'punched_at' => now()->timezone('Asia/Manila')->subDays(5)->format('Y-m-d H:i:s'),
            'punch_code' => 0,
            'verify_mode' => 1,
        ]])->assertAccepted()->assertJsonPath('results.0.status', 'stale');

        // A drifted device clock and a genuine outage backlog look identical in
        // the row, and replaying the first writes fiction. So the default sweep
        // must not touch it.
        $this->artisan('biometric:replay')->assertSuccessful();
        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('old'),
            'processing_status' => 'stale',
        ]);

        // Named explicitly, it is replayed -- the operator has decided.
        Config::set('attendance.biometric_bridge.max_punch_age_hours', 24 * 30);
        $this->artisan('biometric:replay', ['--status' => ['stale']])->assertSuccessful();

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('old'),
            'processing_status' => 'processed',
        ]);
    }

    public function test_an_already_processed_punch_is_never_replayed_into_a_second_attendance_record(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();
        $this->enroll($device, $employee);

        $this->postPunch('done', (string) $employee->id, 0)
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'processed');

        $this->artisan('biometric:replay')->assertSuccessful();
        $this->artisan('biometric:replay', ['--status' => ['rejected']])->assertSuccessful();

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseCount('biometric_scan_events', 1);
    }

    public function test_a_dry_run_reports_the_same_set_without_changing_anything(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $this->postPunch('dry', (string) $employee->id, 0)->assertAccepted();
        $this->enroll($device, $employee);

        $this->artisan('biometric:replay', ['--dry-run' => true])
            ->expectsOutputToContain('Would replay')
            ->expectsOutputToContain('1 punch(es) would be replayed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('dry'),
            'processing_status' => 'unmatched',
        ]);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_a_status_that_cannot_be_replayed_is_refused(): void
    {
        $this->artisan('biometric:replay', ['--status' => ['processed']])->assertFailed();
        $this->artisan('biometric:replay', ['--status' => ['nonsense']])->assertFailed();
    }

    public function test_a_punch_whose_terminal_is_no_longer_registered_is_named_rather_than_crashed_on(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $this->postPunch('orphan', (string) $employee->id, 0)->assertAccepted();

        // The terminal is retired after the punch was stored. Nothing can be
        // derived against it, and the punch must survive untouched.
        $device->forceFill(['is_active' => false])->save();

        $this->artisan('biometric:replay')
            ->expectsOutputToContain('could not be replayed')
            ->assertSuccessful();

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('orphan'),
            'processing_status' => 'unmatched',
        ]);
    }

    public function test_since_limits_the_replay_to_punches_on_or_after_that_date(): void
    {
        $device = $this->device();
        $employee = $this->activeEmployee();

        $this->postPunch('recent', (string) $employee->id, 0)->assertAccepted();
        $this->enroll($device, $employee);

        /*
         * Window opens tomorrow, so today's punch is outside it -- and
         * "tomorrow" has to be read off the office clock, because that is the
         * clock both halves of this assertion keep. The terminal reports local
         * wall time and --since is read as a local date, while the app runs in
         * UTC; taking tomorrow's date from the UTC clock instead asks for a
         * window that has already opened during Manila's small hours, when the
         * two dates disagree, and the punch is then correctly replayed.
         */
        $tomorrow = now()->timezone(config('workforce.timezone'))->addDay()->toDateString();

        $this->artisan('biometric:replay', ['--since' => $tomorrow])
            ->expectsOutputToContain('0 punch(es) replayed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('biometric_punches', [
            'fingerprint' => $this->digest('recent'),
            'processing_status' => 'unmatched',
        ]);
    }

    public function test_an_unreadable_since_date_is_refused(): void
    {
        $this->artisan('biometric:replay', ['--since' => 'last tuesday-ish'])->assertFailed();
    }

    // --- helpers ---------------------------------------------------------

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
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('employment_status', 'active'))
            ->firstOrFail()
            ->employee;
    }

    private function enroll(BiometricDevice $device, Employee $employee): BiometricEnrollment
    {
        return BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'is_active' => true,
            'enrolled_at' => now(),
        ]);
    }

    private function postPunch(string $seed, string $pin, int $punchCode): TestResponse
    {
        return $this->postRaw([[
            'fingerprint' => $this->digest($seed),
            'pin' => $pin,
            'punched_at' => now()->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'punch_code' => $punchCode,
            'verify_mode' => 1,
        ]]);
    }

    /** @param array<int, array<string, mixed>> $punches */
    private function postRaw(array $punches): TestResponse
    {
        $payload = [
            'device_sn' => self::SERIAL,
            'sent_at' => now()->toIso8601String(),
            'punches' => $punches,
        ];

        return $this->postJson('/api/biometric/punches', $payload, [
            'X-Bridge-Signature' => hash_hmac('sha256', json_encode($payload), self::SECRET),
            'X-Bridge-Device' => self::SERIAL,
        ]);
    }

    private function digest(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
