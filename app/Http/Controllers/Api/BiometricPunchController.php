<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreBiometricPunchBatchRequest;
use App\Models\BiometricDevice;
use App\Services\Attendance\BiometricPunchIngestionService;
use Illuminate\Http\JsonResponse;

/**
 * Receives punch batches from the biometric bridge agent.
 *
 * The controller authenticates (by middleware), validates (by form request)
 * and hands off. It holds no protocol knowledge of its own: punch codes,
 * verify modes and device clocks are the ingestion service's business, and
 * attendance rules remain the gateway's. A 2xx from here means the batch is
 * durably stored, which is the only thing that stops the agent resending it.
 */
class BiometricPunchController extends Controller
{
    public function store(
        StoreBiometricPunchBatchRequest $request,
        BiometricPunchIngestionService $ingestion,
    ): JsonResponse {
        $device = BiometricDevice::query()
            ->with('officeLocation')
            ->where('serial_number', $request->validated('device_sn'))
            ->where('is_active', true)
            ->firstOrFail();

        $outcome = $ingestion->ingest($device, $request->validated('punches'));

        return response()->json($outcome, 202);
    }
}
