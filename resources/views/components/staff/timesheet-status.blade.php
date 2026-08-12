@props(['timesheet'])

<section
    @class(['panel', 'staff-timesheet', 'needs-attention' => $timesheet['attention']])
    id="timesheet-status"
    aria-labelledby="timesheet-status-title"
>
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Timesheets</p>
            <h2 id="timesheet-status-title">Timesheet status</h2>
        </div>
        <span class="staff-panel-meta">{{ $timesheet['period_label'] }}</span>
    </div>

    <div class="staff-timesheet-body">
        <p class="staff-status-line staff-status-{{ $timesheet['tone'] }}">
            <span class="staff-status-icon"><x-icon :name="$timesheet['icon']" /></span>
            <span>
                <strong>{{ $timesheet['label'] }}</strong>
                <small>Current period · {{ $timesheet['period_label'] }}</small>
            </span>
        </p>

        <dl class="staff-figure-pair">
            <div>
                <dt>Total hours</dt>
                <dd>{{ $timesheet['total_hours'] }}</dd>
            </div>
            <div>
                <dt>Overtime</dt>
                <dd>{{ $timesheet['overtime_hours'] }}</dd>
            </div>
        </dl>

        @if ($timesheet['attention'])
            <p class="staff-attention-note">
                <x-icon name="alert" />
                <span>
                    {{ $timesheet['status'] === 'rejected'
                        ? 'This period was returned to you. Open it to see the reviewer’s notes.'
                        : 'This period is still a draft. Submit it so your hours can be approved.' }}
                </span>
            </p>
        @endif
    </div>

    <a href="{{ route('timesheets.index') }}" class="panel-footer-link">View timesheet <x-icon name="chevron-right" /></a>
</section>
