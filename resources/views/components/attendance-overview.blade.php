@props(['overview', 'canManageWorkforce' => false])

@php
    // Label every column on the 7-day view; on the 30-day view label every fifth so
    // the axis stays readable instead of turning into a grey smear.
    $labelEvery = $overview['days'] > 14 ? 5 : 1;
    $midTick = (int) round($overview['max'] / 2);
@endphp

<section class="panel attendance-overview" id="attendance-overview" aria-labelledby="attendance-overview-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Time &amp; Attendance</p>
            <h2 id="attendance-overview-title">Attendance overview</h2>
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

    <p class="attendance-overview-caption">
        <span>{{ $overview['range_label'] }}</span>
        <span>Absences count rostered staff with no check-in and no approved leave.</span>
    </p>

    <div class="attendance-legend">
        @foreach ($overview['series'] as $series)
            <div class="attendance-legend-item">
                <span class="attendance-swatch attendance-swatch-{{ $series['key'] }}"></span>
                <span>{{ $series['label'] }}</span>
                <strong>{{ number_format($series['total']) }}</strong>
            </div>
        @endforeach
    </div>

    @if ($overview['tracked'] < 1)
        <div class="compact-empty-state attendance-overview-empty">
            <x-icon name="clock" />
            <p>No attendance, leave, or roster activity in the last {{ $overview['days'] }} days.</p>
        </div>
    @else
        <div class="attendance-chart" data-attendance-chart>
            <div class="attendance-axis" aria-hidden="true">
                <span>{{ number_format($overview['max']) }}</span>
                <span>{{ number_format($midTick) }}</span>
                <span>0</span>
            </div>

            <div class="attendance-plot-scroll">
                <div class="attendance-plot" style="--attendance-columns: {{ count($overview['buckets']) }}">
                    @foreach ($overview['buckets'] as $bucket)
                        @php
                            $readout = collect($overview['series'])
                                ->map(fn (array $series): string => $bucket[$series['key']].' '.strtolower($series['label']))
                                ->implode(', ');
                            $showLabel = $loop->iteration % $labelEvery === 0 || $loop->last;
                        @endphp
                        <div
                            class="attendance-column"
                            tabindex="0"
                            aria-label="{{ $bucket['full_label'] }}: {{ $readout }}."
                            data-attendance-column
                            data-day="{{ $bucket['full_label'] }}"
                            @foreach ($overview['series'] as $series)
                                data-{{ str_replace('_', '-', $series['key']) }}="{{ $bucket[$series['key']] }}"
                            @endforeach
                        >
                            <div class="attendance-track">
                                <div
                                    @class(['attendance-stack', 'is-zero' => $bucket['total'] < 1])
                                    style="height: {{ round($bucket['total'] / $overview['max'] * 100, 2) }}%"
                                >
                                    @foreach ($overview['series'] as $series)
                                        @continue($bucket[$series['key']] < 1)
                                        <i
                                            class="attendance-fill attendance-fill-{{ $series['key'] }}"
                                            style="flex-grow: {{ $bucket[$series['key']] }}"
                                        ></i>
                                    @endforeach
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
        <div class="table-responsive">
            <table class="dashboard-table attendance-data-table">
                <caption class="visually-hidden">Daily attendance breakdown for the last {{ $overview['days'] }} days</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
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
