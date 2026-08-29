@props(['activity'])

{{-- The newest write actions on the system, read off the audit trail. It is a
     window onto Audit Logs, not a second copy of it: the full history, its
     filters and its export all live there, and the panel links straight out. --}}
<section class="panel recent-activity" aria-labelledby="recent-activity-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Security &amp; Compliance</p>
            <h2 id="recent-activity-title">Recent activity</h2>
        </div>
        <a class="btn btn-light dashboard-action" href="{{ route('audit-logs.index') }}">View all</a>
    </div>

    @if ($activity === [])
        <div class="compact-empty-state recent-activity-empty">
            <x-icon name="shield" />
            <p>No recorded activity yet. Write actions are logged here automatically.</p>
        </div>
    @else
        <ol class="activity-feed">
            @foreach ($activity as $entry)
                <li @class(['activity-entry', 'is-failed' => $entry['failed']])>
                    <span class="activity-marker" aria-hidden="true"></span>

                    <div class="activity-body">
                        <p class="activity-summary">
                            <strong>{{ $entry['actor'] }}</strong>
                            {{ strtolower($entry['summary']) }}@if ($entry['subject']) <span class="activity-subject">{{ $entry['subject'] }}</span>@endif
                        </p>
                        <p class="activity-meta">
                            {{ $entry['day_label'] }} · {{ $entry['time_label'] }}
                            @if ($entry['failed'])
                                <span class="activity-failed">rejected</span>
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
