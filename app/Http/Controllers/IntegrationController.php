<?php

namespace App\Http\Controllers;

use App\Http\Requests\Integrations\UpdateAiSchedulingSettingsRequest;
use App\Models\IntegrationEvent;
use App\Services\Integrations\GeminiConnectionService;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(Request $request, AiSchedulingFeatureSettings $aiSettings): View
    {
        $this->authorizeIntegrationAdmin($request);

        return view('integrations.index', [
            'providers' => [
                'gemini' => [
                    'enabled' => (bool) config('integrations.gemini.enabled'),
                    'configured' => filled(config('integrations.gemini.api_key')),
                    'model' => (string) config('integrations.gemini.model'),
                ],
            ],
            'events' => IntegrationEvent::query()->where('provider', 'gemini')->latest()->limit(25)->get(),
            'aiScheduling' => [
                'assistant_enabled' => $aiSettings->assistantEnabled(),
                'gemini_explanations_enabled' => $aiSettings->geminiExplanationsEnabled(),
                'gemini_available' => (bool) config('integrations.gemini.enabled') && filled(config('integrations.gemini.api_key')),
                'source' => $aiSettings->source(),
                'updated_by' => $aiSettings->updatedBy()?->name,
            ],
            'canManageAiScheduling' => $request->user()->hasRole('system-administrator'),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function updateAiScheduling(
        UpdateAiSchedulingSettingsRequest $request,
        AiSchedulingFeatureSettings $aiSettings,
    ): RedirectResponse {
        $assistantEnabled = $request->boolean('assistant_enabled');
        $geminiEnabled = $request->boolean('gemini_explanations_enabled');

        if ($assistantEnabled && $geminiEnabled
            && (! config('integrations.gemini.enabled') || blank(config('integrations.gemini.api_key')))) {
            throw ValidationException::withMessages([
                'gemini_explanations_enabled' => 'Configure and enable the Gemini provider before enabling scheduling explanations.',
            ]);
        }

        $aiSettings->update($request->user(), $assistantEnabled, $geminiEnabled);

        return back()->with('success', $assistantEnabled
            ? 'AI Scheduling Assistant settings updated.'
            : 'AI Scheduling Assistant disabled. Manual scheduling remains available.');
    }

    public function testGemini(Request $request, GeminiConnectionService $service): RedirectResponse
    {
        abort_unless($request->user()->hasRole('system-administrator'), 403);

        $result = $service->test();

        return back()->with($result->success ? 'success' : 'warning', $result->message);
    }

    private function authorizeIntegrationAdmin(Request $request): void
    {
        abort_unless($request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty(), 403);
    }
}
