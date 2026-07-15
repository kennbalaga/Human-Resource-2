<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEvent;
use App\Services\Integrations\ZapierWebhookService;
use App\Services\Integrations\ZoomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeIntegrationAdmin($request);

        return view('integrations.index', [
            'providers' => [
                'gemini' => ['enabled' => (bool) config('integrations.gemini.enabled'), 'configured' => filled(config('integrations.gemini.api_key'))],
                'zapier' => ['enabled' => (bool) config('integrations.zapier.enabled'), 'configured' => filled(config('integrations.zapier.webhook_url'))],
                'zoom' => ['enabled' => (bool) config('integrations.zoom.enabled'), 'configured' => filled(config('integrations.zoom.account_id')) && filled(config('integrations.zoom.client_id')) && filled(config('integrations.zoom.client_secret'))],
            ],
            'events' => IntegrationEvent::query()->latest()->limit(25)->get(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
        ]);
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
