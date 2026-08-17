<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * Issues and verifies the QR code an employee presents at the scanner while the
 * fingerprint terminal is not yet in place.
 *
 * The code carries the employee's own id — the foreign key attendance is
 * written against — together with a signature over that id, the employee
 * number, and a per-employee revision. The signature is what separates this
 * from a printed employee number: the id is public, so without it anyone who
 * knows a colleague's number could generate that colleague's badge on any
 * free QR website and clock them in. The signing key is the application key,
 * which never leaves the server, so a code can only be produced here.
 *
 * What the signature deliberately does NOT solve: a badge is a bearer token,
 * and a photograph of one works as well as the original. That is why the
 * scanner is limited to HR, administrators and department heads standing at
 * the entrance, and why a leaked code can be recalled by bumping the
 * employee's revision.
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

        return $employee;
    }

    /**
     * Retire every copy of this employee's code, printed or saved, and issue a
     * new one. Used when a badge is lost, shared, or photographed.
     */
    public function regenerate(Employee $employee): Employee
    {
        $employee->increment('attendance_qr_revision');

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
                $employee->attendance_qr_revision ?? 1,
            ]),
            (string) config('app.key'),
        ), 0, self::SIGNATURE_LENGTH);
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
