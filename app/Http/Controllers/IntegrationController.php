<?php

namespace App\Http\Controllers;

use App\Http\Requests\Integrations\UpdateAiSchedulingSettingsRequest;
use App\Models\IntegrationEvent;
use App\Services\Integrations\ZapierWebhookService;
use App\Services\Integrations\ZoomService;
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
                'gemini' => ['enabled' => (bool) config('integrations.gemini.enabled'), 'configured' => filled(config('integrations.gemini.api_key'))],
                'zapier' => ['enabled' => (bool) config('integrations.zapier.enabled'), 'configured' => filled(config('integrations.zapier.webhook_url'))],
                'zoom' => ['enabled' => (bool) config('integrations.zoom.enabled'), 'configured' => filled(config('integrations.zoom.account_id')) && filled(config('integrations.zoom.client_id')) && filled(config('integrations.zoom.client_secret'))],
            ],
            'events' => IntegrationEvent::query()->latest()->limit(25)->get(),
            'aiScheduling' => [
                'assistant_enabled' => $aiSettings->assistantEnabled(),
                'gemini_explanations_enabled' => $aiSettings->geminiExplanationsEnabled(),
                'gemini_available' => (bool) config('integrations.gemini.enabled') && filled(config('integrations.gemini.api_key')),
                'source' => $aiSettings->source(),
                'updated_by' => $aiSettings->updatedBy()?->name,
            ],
            'canManageAiScheduling' => $request->user()->hasRole('system-administrator'),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
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

    public function testZapier(Request $request, ZapierWebhookService $service): RedirectResponse
    {
        $this->authorizeIntegrationAdmin($request);
        $result = $service->send('integration.test', ['initiated_by' => $request->user()->id, 'application' => config('app.name')]);

        return back()->with($result->success ? 'success' : 'warning', $result->message);
    }

    public function createZoomMeeting(Request $request, ZoomService $service): RedirectResponse
    {
        $this->authorizeIntegrationAdmin($request);
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'start_time' => ['required', 'date', 'after:now'],
            'duration' => ['required', 'integer', 'between:15,480'],
            'agenda' => ['nullable', 'string', 'max:1000'],
        ]);
        $result = $service->createMeeting($validated);

        return back()
            ->with($result->success ? 'success' : 'warning', $result->message)
            ->with('zoom_meeting', $result->success ? $result->data : null);
    }

    private function authorizeIntegrationAdmin(Request $request): void
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager'])->exists(), 403);
    }
}
