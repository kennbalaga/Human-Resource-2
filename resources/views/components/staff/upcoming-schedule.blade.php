@props(['upcoming'])

<section class="panel staff-upcoming" id="upcoming-schedule" aria-labelledby="upcoming-schedule-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">My schedule</p>
            <h2 id="upcoming-schedule-title">Upcoming schedule</h2>
        </div>
        <span class="staff-panel-meta">{{ $upcoming['range_label'] }}</span>
    </div>

    @if (empty($upcoming['rows']))
        <div class="compact-empty-state">
            <x-icon name="calendar" />
            <p>Nothing published for you yet. Your next shifts appear here once the roster is released.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="dashboard-table dashboard-table-fit staff-upcoming-table">
                <caption class="visually-hidden">My next shifts, {{ $upcoming['range_label'] }}</caption>
                <colgroup>
                    <col style="width: 24%">
                    <col style="width: 26%">
                    <col style="width: 26%">
                    <col style="width: 24%">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Shift</th>
                        <th scope="col">Department</th>
                        <th scope="col">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($upcoming['rows'] as $row)
                        <tr>
                            <th scope="row">
                                <span class="staff-upcoming-date">{{ $row['date_label'] }}</span>
                                <small>{{ $row['weekday'] }}</small>
                            </th>
                            <td>
                                <span
                                    class="staff-shift-pill staff-shift-pill-{{ $row['type'] }}"
                                    @if ($row['color']) style="--shift-color: {{ $row['color'] }}" @endif
                                >{{ $row['shift'] }}</span>
                            </td>
                            <td>{{ $row['type'] === 'shift' ? $row['department'] : '—' }}</td>
                            <td>{{ $row['time'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <a href="{{ route('schedules.index') }}" class="panel-footer-link">Open my schedule calendar <x-icon name="chevron-right" /></a>
</section>
