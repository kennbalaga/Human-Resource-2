@props(['overview', 'canManageWorkforce' => false])

@php
    // Label every column on the 7-day view; on the 30-day view label every fifth so
    // the axis stays readable instead of turning into a grey smear.
    $labelEvery = $overview['days'] > 14 ? 5 : 1;

    // The axis counts hours now, which are rarely whole, so the ticks round to
    // something a reader can hold: "1,400" rather than "1,398.5".
    $axis = fn (float $value): string => number_format($value, $value < 10 ? 1 : 0);
    $peakDate = collect($overview['buckets'])
        ->filter(fn (array $bucket): bool => $bucket['hours'] > 0)
        ->sortByDesc('hours')
        ->first()['date'] ?? null;

    // A rate is null when nothing was measured, which is not the same as zero and
    // must never be printed as "0%".
    $percent = fn (?float $value): string => $value === null
        ? '—'
        : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';

    $hours = fn (?float $value): string => $value === null
        ? '—'
        : rtrim(rtrim(number_format($value, 1), '0'), '.').' h';

    // The four figures worth reading beside the chart. Peak and lowest name the
    // day as well as the number: "1,400 h" is only actionable once you know it
    // was Thursday.
    $tiles = [
        [
            'label' => 'Peak day',
            'detail' => $overview['peak_hours_day']['weekday'] ?? 'No rostered days',
            'value' => $hours($overview['peak_hours_day']['hours'] ?? null),
        ],
        [
            'label' => 'Lowest day',
            'detail' => $overview['lowest_hours_day']['weekday'] ?? 'No rostered days',
            'value' => $hours($overview['lowest_hours_day']['hours'] ?? null),
        ],
        [
            'label' => 'On-time rate',
            // Short enough to survive a quarter of the panel's width: the tile
            // details are clipped rather than wrapped, so the copy has to fit.
            'detail' => 'Of those who came in',
            'value' => $percent($overview['on_time_rate']),
        ],
        [
            'label' => 'Leave requests',
            'detail' => $overview['leave_total'] > 0
                ? $overview['leave_total'].' filed in the window'
                : 'None filed',
            'value' => $overview['leave_pending'] > 0
                ? $overview['leave_pending'].' pending'
                : 'All cleared',
        ],
    ];
@endphp

<section class="panel attendance-overview" id="attendance-overview" aria-labelledby="attendance-overview-title">
    <div class="panel-header">
        <div>
            {{-- The window states itself, because the control that changes it
                 sits on the other side of this header and a fixed kicker would
                 contradict it the moment somebody switched to 30 days. --}}
            <p class="panel-kicker">Trailing {{ $overview['days'] }} days</p>
            <h2 id="attendance-overview-title">Hours worked per day</h2>
        </div>
        <div class="attendance-range" role="group" aria-label="Attendance trend range">
            @foreach (\App\Services\AttendanceOverviewService::RANGES as $range)
                @php $isActive = $overview['days'] === $range; @endphp
                {{-- `employees_page` is dropped rather than carried. The two
                     widgets share one URL, so switching the chart to 30 days
                     used to keep the employee panel on whatever page it was
                     left on -- a range change silently repaginating an
                     unrelated table. Null keys are omitted by the query
                     builder, which is how the parameter is cleared. --}}
                <a
                    href="{{ request()->fullUrlWithQuery(['attendance_days' => $range, 'employees_page' => null]) }}#attendance-overview"
                    @class(['is-active' => $isActive])
                    @if ($isActive) aria-current="true" @endif
                >{{ $range }} days</a>
            @endforeach
        </div>
    </div>

    {{-- The headline the panel is read for: hours actually worked in the window,
         and whether they were worked on time. Everything below breaks it down. --}}
    <div class="attendance-headline">
        <div class="attendance-headline-figure">
            <p class="attendance-hours">
                <strong>{{ number_format($overview['hours_logged']) }}</strong>
                <span>hrs</span>
            </p>
            <p class="attendance-hours-caption">Total hours logged · {{ $overview['range_label'] }}</p>
        </div>

        <div class="attendance-headline-rate">
            <p class="attendance-rate-label">Overall attendance</p>
            <p class="attendance-rate-value">{{ $percent($overview['on_time_rate']) }} on-time rate</p>
            @if ($canManageWorkforce)
                <a href="{{ route('attendance.reports.index') }}">View attendance report <x-icon name="chevron-right" /></a>
            @endif
        </div>
    </div>

    <dl class="attendance-tiles">
        @foreach ($tiles as $tile)
            <div class="attendance-tile">
                <div>
                    <dt>{{ $tile['label'] }}</dt>
                    <dd class="attendance-tile-detail">{{ $tile['detail'] }}</dd>
                </div>
                <dd class="attendance-tile-value">{{ $tile['value'] }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($overview['tracked'] < 1)
        <div class="compact-empty-state attendance-overview-empty">
            <x-icon name="clock" />
            <p>No attendance, leave, or roster activity in the last {{ $overview['days'] }} days.</p>
        </div>
    @else
        <div class="attendance-chart" data-attendance-chart>
            <div class="attendance-axis" aria-hidden="true">
                <span>{{ $axis($overview['hours_max']) }}</span>
                <span>{{ $axis($overview['hours_max'] / 2) }}</span>
                <span>0</span>
            </div>

            <div class="attendance-plot-scroll">
                <div class="attendance-plot" style="--attendance-columns: {{ count($overview['buckets']) }}">
                    @foreach ($overview['buckets'] as $bucket)
                        @php
                            $isPeak = $peakDate !== null && $bucket['date'] === $peakDate;
                            $showLabel = $loop->iteration % $labelEvery === 0 || $loop->last;
                            // The day's headcounts stay on the column as data
                            // attributes: the hover readout still breaks the
                            // hours down into who turned up to work them.
                            $readout = collect($overview['series'])
                                ->map(fn (array $series): string => $bucket[$series['key']].' '.strtolower($series['label']))
                                ->implode(', ');
                        @endphp
                        <div
                            @class(['attendance-column', 'is-peak' => $isPeak])
                            tabindex="0"
                            aria-label="{{ $bucket['full_label'] }}: {{ $hours($bucket['hours']) }} worked — {{ $readout }}."
                            data-attendance-column
                            data-day="{{ $bucket['full_label'] }}"
                            data-hours="{{ $bucket['hours'] }}"
                            @foreach ($overview['series'] as $series)
                                data-{{ str_replace('_', '-', $series['key']) }}="{{ $bucket[$series['key']] }}"
                            @endforeach
                        >
                            <div class="attendance-track">
                                <div
                                    @class(['attendance-stack', 'is-zero' => $bucket['hours'] <= 0])
                                    style="height: {{ round(min(1, $bucket['hours'] / $overview['hours_max']) * 100, 2) }}%"
                                >
                                    <i class="attendance-fill attendance-fill-hours"></i>
                                </div>
                            </div>
                            <small aria-hidden="true">{{ $showLabel ? ($labelEvery === 1 ? $bucket['weekday'] : $bucket['label']) : '' }}</small>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <details class="attendance-table-view">
        <summary>View data table</summary>
        <p class="attendance-overview-caption">Absences count rostered staff with no check-in and no approved leave.</p>
        <div class="table-responsive">
            <table class="dashboard-table attendance-data-table">
                <caption class="visually-hidden">Hours worked and the daily attendance breakdown for the last {{ $overview['days'] }} days</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        {{-- The charted measure leads; the four headcounts behind
                             it follow, which is the breakdown the chart used to
                             draw and the only place it is still written out. --}}
                        <th scope="col">Hours</th>
                        @foreach ($overview['series'] as $series)
                            <th scope="col">{{ $series['label'] }}</th>
                        @endforeach
                        <th scope="col">Tracked</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($overview['buckets'] as $bucket)
                        <tr>
                            <th scope="row">{{ $bucket['full_label'] }}</th>
                            <td>{{ $hours($bucket['hours']) }}</td>
                            @foreach ($overview['series'] as $series)
                                <td>{{ number_format($bucket[$series['key']]) }}</td>
                            @endforeach
                            <td>{{ number_format($bucket['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Total</th>
                        <td>{{ number_format($overview['hours_logged']) }} h</td>
                        @foreach ($overview['series'] as $series)
                            <td>{{ number_format($series['total']) }}</td>
                        @endforeach
                        <td>{{ number_format($overview['tracked']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </details>

    {{-- Attendance reports are manager-only, so everyone else is sent to their own record. --}}
    <a
        href="{{ $canManageWorkforce
            ? route('attendance.reports.index', ['date_from' => $overview['from'], 'date_to' => $overview['to']])
            : route('attendance.index') }}"
        class="panel-footer-link"
    >
        {{ $canManageWorkforce ? 'Open attendance reports' : 'Open my attendance' }} <x-icon name="chevron-right" />
    </a>
</section>
