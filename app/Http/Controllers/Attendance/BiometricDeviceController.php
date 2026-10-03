<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\SaveBiometricDeviceRequest;
use App\Models\BiometricDevice;
use Illuminate\Http\RedirectResponse;

/**
 * Registers the physical terminals.
 *
 * Thin on purpose: registering a device is a handful of fields, and the rule
 * that matters is in SaveBiometricDeviceRequest rather than here. Enrolments
 * hang off a device, so this is the prerequisite for everything on the roster
 * page -- and because the punch endpoint refuses any serial without an active
 * row, it is also the allowlist.
 *
 * There is no destroy action. biometric_enrollments.biometric_device_id is
 * cascadeOnDelete, so deleting a device would silently take its entire
 * enrolment history with it. A terminal that leaves service is deactivated,
 * which stops it posting while keeping the record of who was on it.
 */
class BiometricDeviceController extends Controller
{
    public function store(SaveBiometricDeviceRequest $request): RedirectResponse
    {
        $device = BiometricDevice::query()->create($request->validated() + ['is_active' => true]);

        return redirect()
            ->route('settings.biometric-terminals.index', ['device' => $device->id])
            ->with('success', $device->name.' is registered. The bridge agent may now post punches for serial '.$device->serial_number.'.');
    }

    public function update(SaveBiometricDeviceRequest $request, BiometricDevice $biometricDevice): RedirectResponse
    {
        $biometricDevice->fill($request->validated());
        $biometricDevice->is_active = $request->boolean('is_active');
        $biometricDevice->save();

        return redirect()
            ->route('settings.biometric-terminals.index', ['device' => $biometricDevice->id])
            ->with('success', $biometricDevice->name.' updated.'.(
                $biometricDevice->is_active
                    ? ''
                    : ' It is now inactive, so punches from it are refused — the enrolments on it are kept.'
            ));
    }
}
