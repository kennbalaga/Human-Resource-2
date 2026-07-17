@extends('layouts.app')

@section('title', 'Integrations')

@section('content')
    <section class="page-heading workforce-heading"><div><p class="eyebrow">System Administration</p><h1>AI Integration</h1><p>Manage the Gemini-powered features used for workforce insights and scheduling explanations.</p></div></section>
    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if(session('warning'))<div class="attendance-alert attendance-alert-warning"><x-icon name="plug" /><span>{{ session('warning') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="panel ai-system-control" aria-labelledby="aiSchedulingControlTitle">
        <div class="panel-header">
            <div><p class="panel-kicker">Scheduling intelligence</p><h2 id="aiSchedulingControlTitle">AI Scheduling Assistant</h2></div>
            <x-status-badge :status="$aiScheduling['assistant_enabled'] ? 'active' : 'inactive'" />
        </div>
        <div class="ai-system-control-layout">
            <div class="ai-system-control-summary">
                <span class="integration-logo"><x-icon name="ai" /></span>
                <div>
                    <strong>Global workforce scheduling control</strong>
                    <p>Controls whether authorized managers can generate advisory employee recommendations. Existing manual scheduling always remains available.</p>
                    <small>
                        {{ $aiScheduling['source'] === 'admin_setting' ? 'Controlled by saved admin settings' : 'Using the .env deployment default' }}
                        @if($aiScheduling['updated_by']) · Last changed by {{ $aiScheduling['updated_by'] }}@endif
                    </small>
                </div>
            </div>

            <form method="POST" action="{{ route('integrations.ai-scheduling.update') }}" class="ai-system-control-form" data-ai-system-settings>
                @csrf @method('PATCH')
                <div class="settings-toggle-list">
                    <label class="settings-toggle">
                        <span><strong>Enable AI Scheduling Assistant</strong><small>Shows the advisory assistant in the Assign shift workspace and enables recommendation endpoints.</small></span>
                        <input type="checkbox" name="assistant_enabled" value="1" data-ai-assistant-toggle @checked(old('assistant_enabled', $aiScheduling['assistant_enabled'])) @disabled(! $canManageAiScheduling)><i aria-hidden="true"></i>
                    </label>
                    <label class="settings-toggle" data-gemini-setting>
                        <span><strong>Use Gemini explanations</strong><small>{{ $aiScheduling['gemini_available'] ? 'Adds a privacy-filtered explanation without changing Laravel ranking.' : 'Requires GEMINI_ENABLED and a configured API key in the deployment environment.' }}</small></span>
                        <input type="checkbox" name="gemini_explanations_enabled" value="1" data-ai-gemini-toggle @checked(old('gemini_explanations_enabled', $aiScheduling['gemini_explanations_enabled'])) @disabled(! $canManageAiScheduling || ! $aiScheduling['gemini_available'])><i aria-hidden="true"></i>
                    </label>
                </div>
                @if($canManageAiScheduling)
                    <div class="ai-system-control-actions"><span data-ai-settings-note>Changes apply immediately to all authorized scheduling users.</span><button class="btn btn-primary" type="submit"><x-icon name="check-circle" /> Save AI settings</button></div>
                @else
                    <div class="settings-security-note"><x-icon name="shield" /><p>Only a System Administrator can change this global setting. HR Managers can view its current status.</p></div>
                @endif
            </form>
        </div>
    </section>

    <section class="integration-card-grid">
        <article class="integration-card"><span class="integration-logo"><x-icon name="ai" /></span><div><h2>Gemini AI</h2><p>Privacy-filtered workforce insights and advisory scheduling explanations.</p><div class="integration-state"><x-status-badge :status="$providers['gemini']['enabled'] && $providers['gemini']['configured'] ? 'active' : 'inactive'" /><span>{{ $providers['gemini']['configured'] ? 'API credentials detected' : 'API credentials missing' }}</span></div></div></article>
    </section>

    <section class="panel workforce-table-panel"><div class="panel-header"><div><p class="panel-kicker">Resilience monitoring</p><h2>Recent Gemini attempts</h2></div><span class="history-caption">Last 25</span></div><div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr><th>Time</th><th>Feature</th><th>Status</th><th>HTTP</th><th>Duration</th><th>Message</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->created_at->format('M j, g:i A') }}</td><td>{{ str($event->event_type)->headline() }}</td><td><x-status-badge :status="$event->status === 'success' ? 'active' : 'rejected'" /></td><td>{{ $event->response_code ?? '—' }}</td><td>{{ $event->duration_ms !== null ? $event->duration_ms.'ms' : '—' }}</td><td>{{ $event->message }}</td></tr>@empty<tr><td colspan="6" class="empty-table-cell"><x-icon name="ai" /><strong>No Gemini attempts yet</strong><span>AI features remain optional and do not affect manual HR workflows.</span></td></tr>@endforelse</tbody></table></div></section>
@endsection
