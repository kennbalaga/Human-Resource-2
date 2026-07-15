@extends('layouts.app')

@section('title', 'Integrations')

@section('content')
    <section class="page-heading workforce-heading"><div><p class="eyebrow">System Administration</p><h1>External Integrations</h1><p>Optional connections are isolated from core HR transactions and fail safely when unavailable.</p></div></section>
    @if(session('success'))<div class="attendance-alert attendance-alert-success"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>@endif
    @if(session('warning'))<div class="attendance-alert attendance-alert-warning"><x-icon name="plug" /><span>{{ session('warning') }}</span></div>@endif
    @if($errors->any())<div class="attendance-alert attendance-alert-danger"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>@endif

    <section class="integration-card-grid">
        @foreach(['gemini' => ['Gemini AI','ai','Aggregate workforce insights'], 'zapier' => ['Zapier','plug','Approval event webhooks'], 'zoom' => ['Zoom','video','Secure workforce meetings']] as $key => $details)
            <article class="integration-card"><span class="integration-logo"><x-icon :name="$details[1]" /></span><div><h2>{{ $details[0] }}</h2><p>{{ $details[2] }}</p><div class="integration-state"><x-status-badge :status="$providers[$key]['enabled'] && $providers[$key]['configured'] ? 'active' : 'inactive'" /><span>{{ $providers[$key]['configured'] ? 'Credentials detected' : 'Credentials missing' }}</span></div></div></article>
        @endforeach
    </section>

    <section class="integration-actions-grid">
        <article class="panel integration-action-panel"><div class="panel-header"><div><p class="panel-kicker">Webhook delivery</p><h2>Test Zapier</h2></div></div><div class="integration-action-body"><p>Send a non-sensitive test event to the configured Catch Hook URL.</p><form method="POST" action="{{ route('integrations.zapier.test') }}">@csrf<button class="btn btn-outline-primary" type="submit"><x-icon name="plug" /> Send test event</button></form></div></article>
        <article class="panel integration-action-panel"><div class="panel-header"><div><p class="panel-kicker">Server-to-Server OAuth</p><h2>Create Zoom meeting</h2></div></div><form class="integration-meeting-form" method="POST" action="{{ route('integrations.zoom.meetings.store') }}">@csrf<label><span>Topic</span><input name="topic" required maxlength="200"></label><label><span>Starts</span><input type="datetime-local" name="start_time" required></label><label><span>Duration</span><input type="number" name="duration" min="15" max="480" value="60" required></label><label class="full-width"><span>Agenda</span><textarea name="agenda" rows="2" maxlength="1000"></textarea></label><button class="btn btn-primary" type="submit"><x-icon name="video" /> Create meeting</button></form>
        @if(session('zoom_meeting'))<div class="zoom-result"><strong>Meeting {{ session('zoom_meeting.meeting_id') }}</strong><a href="{{ session('zoom_meeting.join_url') }}" target="_blank" rel="noopener">Open join link</a></div>@endif</article>
    </section>

    <section class="panel workforce-table-panel"><div class="panel-header"><div><p class="panel-kicker">Resilience monitoring</p><h2>Recent integration attempts</h2></div><span class="history-caption">Last 25</span></div><div class="table-responsive"><table class="dashboard-table workforce-table"><thead><tr><th>Time</th><th>Provider</th><th>Event</th><th>Status</th><th>HTTP</th><th>Duration</th><th>Message</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->created_at->format('M j, g:i A') }}</td><td>{{ str($event->provider)->headline() }}</td><td>{{ $event->event_type }}</td><td><x-status-badge :status="$event->status === 'success' ? 'active' : 'rejected'" /></td><td>{{ $event->response_code ?? '—' }}</td><td>{{ $event->duration_ms !== null ? $event->duration_ms.'ms' : '—' }}</td><td>{{ $event->message }}</td></tr>@empty<tr><td colspan="7" class="empty-table-cell"><x-icon name="plug" /><strong>No integration attempts yet</strong><span>Disabled integrations do not affect HR workflows.</span></td></tr>@endforelse</tbody></table></div></section>
@endsection
