@props(['overview'])

<section class="panel shift-overview" id="shift-overview" aria-labelledby="shift-overview-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Shift &amp; Schedule Management</p>
            <h2 id="shift-overview-title">Today’s shift overview</h2>
        </div>
        <span class="shift-overview-date">{{ $overview['date_label'] }}</span>
    </div>

    <p class="shift-overview-caption">
        <span>{{ number_format($overview['totals']['assigned']) }} assigned across {{ count($overview['shifts']) }} shift {{ Str::plural('pool', count($overview['shifts'])) }}</span>
        <span>Missing counts rostered staff with no check-in and no approved leave.</span>
    </p>

    @if (! $overview['rostered'])
        <div class="compact-empty-state shift-overview-empty">
            <x-icon name="calendar" />
            <p>Nobody is rostered today. Publish a roster to see each shift pool fill in here.</p>
        </div>
    @else
        <div class="shift-pool-grid">
            @foreach ($overview['shifts'] as $shift)
                @php
                    // The bar is drawn from the pool's own head counts, so an unrostered
                    // pool renders as an empty track rather than a divide-by-zero.
                    $segments = [
                        ['key' => 'clocked-in', 'count' => $shift['clocked_in']],
                        ['key' => 'on-leave', 'count' => $shift['on_leave']],
                        ['key' => 'missing', 'count' => $shift['missing']],
                    ];
                @endphp
                <article
                    @class(['shift-pool', 'is-unstaffed' => $shift['assigned'] < 1])
                    style="--shift-color: {{ $shift['color'] }}"
                    aria-labelledby="shift-pool-{{ $shift['id'] }}-title"
                >
                    <header class="shift-pool-header">
                        <span class="shift-pool-dot" aria-hidden="true"></span>
                        <h3 id="shift-pool-{{ $shift['id'] }}-title">{{ $shift['name'] }}</h3>
                        @if ($shift['crosses_midnight'])
                            <span class="shift-pool-tag">Overnight</span>
                        @endif
                    </header>

                    <p class="shift-pool-start">
                        <span>Starts</span>
                        <strong>{{ $shift['start_time'] }}</strong>
                        <small>ends {{ $shift['end_time'] }}</small>
                    </p>

                    <div
                        class="shift-pool-bar"
                        role="img"
                        aria-label="{{ $shift['assigned'] }} assigned, {{ $shift['clocked_in'] }} clocked in, {{ $shift['missing'] }} missing."
                    >
                        @foreach ($segments as $segment)
                            @continue($segment['count'] < 1)
                            <i class="shift-pool-fill shift-pool-fill-{{ $segment['key'] }}" style="flex-grow: {{ $segment['count'] }}"></i>
                        @endforeach
                    </div>

                    <dl class="shift-pool-stats">
                        <div>
                            <dt>Assigned</dt>
                            <dd>{{ number_format($shift['assigned']) }}</dd>
                        </div>
                        <div class="is-clocked-in">
                            <dt>Clocked in</dt>
                            <dd>{{ number_format($shift['clocked_in']) }}</dd>
                        </div>
                        <div @class(['is-missing' => $shift['missing'] > 0])>
                            <dt>Missing</dt>
                            <dd>{{ number_format($shift['missing']) }}</dd>
                        </div>
                    </dl>

                    <p class="shift-pool-footnote">
                        @if ($shift['assigned'] < 1)
                            Nobody rostered on this shift.
                        @else
                            {{ rtrim(rtrim(number_format($shift['coverage'], 1), '0'), '.') }}% clocked in{{ $shift['on_leave'] > 0 ? ', ' . number_format($shift['on_leave']) . ' on approved leave' : '' }}.
                        @endif
                    </p>
                </article>
            @endforeach
        </div>

        <details class="shift-table-view">
            <summary>View data table</summary>
            <div class="table-responsive">
                <table class="dashboard-table shift-data-table">
                    <caption class="visually-hidden">Shift pool coverage for {{ $overview['date_label'] }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Shift</th>
                            <th scope="col">Starts</th>
                            <th scope="col">Assigned</th>
                            <th scope="col">Clocked in</th>
                            <th scope="col">Missing</th>
                            <th scope="col">On leave</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($overview['shifts'] as $shift)
                            <tr>
                                <th scope="row">{{ $shift['name'] }}</th>
                                <td>{{ $shift['start_time'] }}</td>
                                <td>{{ number_format($shift['assigned']) }}</td>
                                <td>{{ number_format($shift['clocked_in']) }}</td>
                                <td>{{ number_format($shift['missing']) }}</td>
                                <td>{{ number_format($shift['on_leave']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">Total</th>
                            <td>—</td>
                            <td>{{ number_format($overview['totals']['assigned']) }}</td>
                            <td>{{ number_format($overview['totals']['clocked_in']) }}</td>
                            <td>{{ number_format($overview['totals']['missing']) }}</td>
                            <td>{{ number_format($overview['totals']['on_leave']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </details>
    @endif

    <a href="{{ route('schedules.index', ['date' => $overview['date']]) }}" class="panel-footer-link">
        Open shift &amp; schedule management <x-icon name="chevron-right" />
    </a>
</section>
