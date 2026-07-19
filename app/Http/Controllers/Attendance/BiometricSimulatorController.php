<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\SimulateBiometricScanRequest;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Services\BiometricAttendanceGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class BiometricSimulatorController extends Controller
{
    public function store(
        SimulateBiometricScanRequest $request,
        BiometricAttendanceGateway $gateway,
    ): RedirectResponse {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $employee = Employee::query()->findOrFail($request->integer('employee_id'));
        $office = OfficeLocation::query()->where('is_active', true)->firstOrFail();
        $device = BiometricDevice::query()->updateOrCreate(
            ['code' => 'local-biometric-simulator'],
            [
                'office_location_id' => $office->id,
                'name' => 'Local Biometric Simulator',
                'provider' => 'simulator',
                'serial_number' => 'SIMULATOR-ONLY',
                'is_active' => true,
                'configuration' => ['production_allowed' => false],
            ],
        );

        $enrollment = BiometricEnrollment::query()->updateOrCreate(
            [
                'biometric_device_id' => $device->id,
                'employee_id' => $employee->id,
            ],
            [
                'external_user_id' => 'simulated-employee-'.$employee->id,
                'is_active' => true,
                'enrolled_at' => now(),
            ],
        );

        $event = $gateway->receive(
            $device,
            (string) Str::uuid(),
            $enrollment->external_user_id,
            $request->validated('event_type'),
            now(),
            ['verification_mode' => 'fingerprint-1:n', 'simulated' => true],
        );

        if ($event->status !== 'processed') {
            return back()->with('warning', 'Simulated scan was '.$event->status.': '.$event->failure_reason);
        }

        return back()->with('success', sprintf(
            'Simulated biometric %s processed for %s. No fingerprint data was stored.',
            str_replace('_', '-', $event->event_type),
            $employee->full_name,
        ));
    }
}
