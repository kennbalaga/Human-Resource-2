@props(['analytics'])

<section class="panel staff-analytics" id="my-analytics" aria-labelledby="my-analytics-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Personal performance</p>
            <h2 id="my-analytics-title">My workforce analytics</h2>
        </div>
        <span class="staff-panel-meta">{{ $analytics['range_label'] }}</span>
    </div>

    <div class="staff-analytics-body">
        <ul class="staff-metric-list">
            @foreach ($analytics['metrics'] as $metric)
                <li class="staff-metric">
                    <span class="staff-metric-bullet" aria-hidden="true"></span>
                    <span class="staff-metric-copy">
                        <strong>{{ $metric['label'] }}</strong>
                        <small>{{ $metric['detail'] }}</small>
                    </span>
                    <b>{{ $metric['value'] }}</b>
                </li>
            @endforeach
        </ul>

        <figure class="staff-trend-figure">
            <figcaption>
                <strong>Monthly attendance rate</strong>
                <span>My own attendance over the last {{ count($analytics['trend']) }} months</span>
            </figcaption>

            @if (! $analytics['trend_tracked'])
                <div class="compact-empty-state">
                    <x-icon name="trend" />
                    <p>No closed rostered days yet, so there is nothing to plot.</p>
                </div>
            @else
                <div class="staff-trend" data-staff-trend>
                    <div class="staff-trend-axis" aria-hidden="true">
                        <span>100%</span>
                        <span>50%</span>
                        <span>0</span>
                    </div>

                    <div class="staff-trend-plot">
                        <div class="staff-trend-grid" aria-hidden="true"><i></i><i></i><i></i></div>

                        @foreach ($analytics['trend'] as $month)
                            <div
                                @class(['staff-trend-column', 'is-current' => $month['is_current'], 'is-untracked' => ! $month['tracked']])
                                tabindex="0"
                                aria-label="{{ $month['full_label'] }}: {{ $month['tracked'] ? rtrim(rtrim(number_format($month['rate'], 1), '0'), '.').'% attendance rate, '.$month['present'].' present, '.$month['late'].' late, '.$month['absent'].' absent' : 'no rostered days' }}."
                                data-month="{{ $month['full_label'] }}"
                                data-rate="{{ $month['tracked'] ? rtrim(rtrim(number_format($month['rate'], 1), '0'), '.').'%' : 'No data' }}"
                                data-present="{{ $month['present'] }}"
                                data-late="{{ $month['late'] }}"
                                data-absent="{{ $month['absent'] }}"
                            >
                                <div class="staff-trend-track">
                                    @if ($month['tracked'])
                                        <i class="staff-trend-fill" style="height: {{ max(1.5, $month['rate']) }}%">
                                            {{-- Only the current month is direct-labelled, and the label rides
                                                 its own column's cap; the rest are carried by the axis, the
                                                 hover readout, and the data table below. --}}
                                            @if ($month['is_current'])
                                                <b class="staff-trend-value">{{ rtrim(rtrim(number_format($month['rate'], 1), '0'), '.') }}%</b>
                                            @endif
                                        </i>
                                    @else
                                        <i class="staff-trend-void" aria-hidden="true"></i>
                                    @endif
                                </div>
                                <small>{{ $month['label'] }}</small>
                            </div>
                        @endforeach
                    </div>
                </div>

                <details class="staff-trend-table-view">
                    <summary>View data table</summary>
                    <div class="table-responsive">
                        <table class="dashboard-table dashboard-table-fit">
                            <caption class="visually-hidden">My monthly attendance rate</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Month</th>
                                    <th scope="col">Rate</th>
                                    <th scope="col">Present</th>
                                    <th scope="col">Late</th>
                                    <th scope="col">Absent</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($analytics['trend'] as $month)
                                    <tr>
                                        <th scope="row">{{ $month['full_label'] }}</th>
                                        <td>{{ $month['tracked'] ? rtrim(rtrim(number_format($month['rate'], 1), '0'), '.').'%' : '—' }}</td>
                                        <td>{{ $month['present'] }}</td>
                                        <td>{{ $month['late'] }}</td>
                                        <td>{{ $month['absent'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </figure>
    </div>
</section>
