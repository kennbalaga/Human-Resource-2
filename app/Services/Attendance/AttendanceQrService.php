<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues and verifies the QR code an employee presents at the scanner while the
 * fingerprint terminal is not yet in place.
 *
 * The code carries the employee's own id — the foreign key attendance is
 * written against — together with a signature over that id, the employee
 * number, signed with a secret held against that employee in the database. The
 * signature is what separates this from a printed employee number: the id is
 * public, so without it anyone who knows a colleague's number could generate
 * that colleague's badge on any free QR website and clock them in.
 *
 * The secret deliberately lives in the database and not in configuration. An
 * APP_KEY belongs to one installation and is generated per install, so signing
 * with it meant two machines sharing this database disagreed about what a
 * person's badge was, and a badge downloaded on one was refused by the other.
 *
 * What the signature deliberately does NOT solve: a badge is a bearer token,
 * and a photograph of one works as well as the original. That is why the
 * scanner is limited to HR, administrators and department heads standing at
 * the entrance, and why a leaked code can be recalled by
 * rotating that employee's secret.
 */
class AttendanceQrService
{
    /**
     * Version prefix. Scanning is strict about it, so the payload format can be
     * changed later without old badges silently resolving to the wrong person.
     */
    public const PREFIX = 'HRMS-ATT1';

    private const SIGNATURE_LENGTH = 20;

    public function payloadFor(Employee $employee): string
    {
        return implode('.', [
            self::PREFIX,
            $employee->id,
            $this->signature($employee),
        ]);
    }

    /**
     * Resolve a scanned string back to the employee it was issued to.
     *
     * @throws ValidationException when the code is malformed, unknown, no longer
     *                             signed for that employee, or belongs to someone
     *                             who is no longer active.
     */
    public function resolve(string $payload): Employee
    {
        $parts = explode('.', trim($payload));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[1])) {
            $this->reject('That is not a valid employee attendance QR code.');
        }

        $employee = Employee::query()->with(['department', 'user'])->find((int) $parts[1]);

        if ($employee === null) {
            $this->reject('This QR code does not match any employee record.');
        }

        // hash_equals rather than === so a mismatch costs the same time to
        // detect wherever it falls in the string.
        if (! hash_equals($this->signature($employee), $parts[2])) {
            $this->reject('This QR code is no longer valid. Ask HR to issue a new one.');
        }

        if ($employee->employment_status !== 'active') {
            $this->reject("{$employee->full_name} is not an active employee.");
        }

        // A printed badge outlives the record it was issued against. Without
        // this, the one person HR has filed away is the one whose card still
        // opens the day.
        if ($employee->isArchived()) {
            $this->reject("{$employee->full_name}'s record is archived. Ask HR before recording attendance.");
        }

        return $employee;
    }

    /**
     * Retire every copy of this employee's code, printed or saved, and issue a
     * new one. Used when a badge is lost, shared, or photographed.
     */
    public function regenerate(Employee $employee): Employee
    {
        $employee->forceFill(['attendance_qr_secret' => Str::random(64)])->save();

        return $employee->refresh();
    }

    private function signature(Employee $employee): string
    {
        return substr(hash_hmac(
            'sha256',
            implode('|', [
                self::PREFIX,
                $employee->id,
                $employee->employee_number,
            ]),
            $this->secretFor($employee),
        ), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * The employee's signing secret, which lives in the database rather than in
     * any one installation's configuration — that is what lets a badge
     * downloaded on one machine be read by a scanner running on another.
     *
     * Issued on first use so that staff added by an import, a seeder, or a
     * direct insert still get a working badge without a separate backfill.
     */
    private function secretFor(Employee $employee): string
    {
        if (blank($employee->attendance_qr_secret)) {
            $this->regenerate($employee);
        }

        return (string) $employee->attendance_qr_secret;
    }

    /**
     * Every rejection is reported on the same field so the scanner can show one
     * consistent "not accepted" state.
     *
     *
     * @return never
     *
     * @throws ValidationException
     */
    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['qr_payload' => $message]);
    }
}
