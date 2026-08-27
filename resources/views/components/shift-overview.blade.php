@props(['overview'])

@php
    // Staffed pools carry the day and keep the full card. Pools nobody was rostered
    // onto still have to be visible -- an empty Night shift is the thing a charge
    // nurse most needs to notice -- but they say all they have to say in two lines,
    // so they drop to a compact strip instead of four columns of zeroes.
    $staffed = collect($overview['shifts'])->filter(fn (array $shift): bool => $shift['assigned'] > 0)->values();
    $unstaffed = collect($overview['shifts'])->filter(fn (array $shift): bool => $shift['assigned'] < 1)->values();

    // The legend only names the states the day actually put on the board. Carrying a
    // Missing key through a morning where nobody is late explains a colour that is
    // not on screen, which is noise where the panel can least afford it.
    $legend = collect([
        ['key' => 'clocked-in', 'label' => 'Clocked in', 'show' => true],
        ['key' => 'on-leave', 'label' => 'On leave', 'show' => true],
        ['key' => 'missing', 'label' => 'Missing', 'show' => $staffed->contains(fn (array $shift): bool => $shift['missing'] > 0 && ! $shift['awaiting'])],
        ['key' => 'awaiting', 'label' => 'Not yet in', 'show' => $staffed->contains(fn (array $shift): bool => $shift['missing'] > 0 && $shift['awaiting'])],
    ])->where('show')->values();
@endphp

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
        <span class="shift-overview-note">Missing counts rostered staff with no check-in and no approved leave.</span>
    </p>

    @if (! $overview['rostered'])
        <div class="compact-empty-state shift-overview-empty">
            <x-icon name="calendar" />
            <p>Nobody is rostered today. Publish a roster to see each shift pool fill in here.</p>
        </div>
    @else
        {{--
            The bar carries the panel's colours and nothing used to say what they
            meant. The quiet key is the one that matters most: before a shift has had
            time to fill, an absent employee has not missed anything yet.
        --}}
        <div class="shift-legend-row">
            <ul class="shift-legend">
                @foreach ($legend as $key)
                    <li><i class="shift-legend-key shift-pool-fill-{{ $key['key'] }}" aria-hidden="true"></i>{{ $key['label'] }}</li>
                @endforeach
            </ul>
            <span class="shift-overview-asof">as of {{ $overview['as_of'] }}</span>
        </div>

        {{--
            Staffed pools take the left column and the unstaffed strip the right, so
            the shifts carrying people are never crowded out by the ones that are not.
            Either side takes the full width when the other is empty.
        --}}
        <div @class(['shift-overview-body', 'is-split' => $staffed->isNotEmpty() && $unstaffed->isNotEmpty()])>
            @if ($staffed->isNotEmpty())
                <div class="shift-pool-grid">
                    @foreach ($staffed as $shift)
                        @php
                            // The bar is drawn from the pool's own head counts. The
                            // third segment is the same people either way -- only its
                            // colour changes, so a shift minutes old reads calm and a
                            // late one does not.
                            $segments = [
                                ['key' => 'clocked-in', 'count' => $shift['clocked_in']],
                                ['key' => 'on-leave', 'count' => $shift['on_leave']],
                                ['key' => $shift['awaiting'] ? 'awaiting' : 'missing', 'count' => $shift['missing']],
                            ];
                            $absentLabel = $shift['awaiting'] ? 'Not yet in' : 'Missing';
                        @endphp
                        <article
                            class="shift-pool"
                            data-phase="{{ $shift['phase'] }}"
                            aria-labelledby="shift-pool-{{ $shift['id'] }}-title"
                        >
                            <header class="shift-pool-header">
                                <h3 id="shift-pool-{{ $shift['id'] }}-title">{{ $shift['name'] }}</h3>
                                <span @class(['shift-pool-tag', 'is-live' => in_array($shift['phase'], ['starting', 'active'], true)])>
                                    {{ $shift['phase_label'] }}
                                </span>
                            </header>

                            <p class="shift-pool-span">
                                <strong>{{ $shift['span_label'] }}</strong>
                                <small>{{ $shift['duration_label'] }}</small>
                                @if ($shift['crosses_midnight'])
                                    <small class="shift-pool-overnight">overnight</small>
                                @endif
                            </p>

                            <dl class="shift-pool-stats">
                                <div>
                                    <dt>Assigned</dt>
                                    <dd>{{ number_format($shift['assigned']) }}</dd>
                                </div>
                                <div>
                                    <dt>Clocked in</dt>
                                    <dd>{{ number_format($shift['clocked_in']) }}</dd>
                                </div>
                                <div @class(['is-missing' => $shift['missing'] > 0 && ! $shift['awaiting']])>
                                    <dt>{{ $absentLabel }}</dt>
                                    <dd>{{ number_format($shift['missing']) }}</dd>
                                </div>
                            </dl>

                            <div
                                class="shift-pool-bar"
                                role="img"
                                aria-label="{{ $shift['assigned'] }} assigned, {{ $shift['clocked_in'] }} clocked in, {{ $shift['on_leave'] }} on approved leave, {{ $shift['missing'] }} {{ strtolower($absentLabel) }}."
                            >
                                @foreach ($segments as $segment)
                                    @continue($segment['count'] < 1)
                                    <i class="shift-pool-fill shift-pool-fill-{{ $segment['key'] }}" style="flex-grow: {{ $segment['count'] }}"></i>
                                @endforeach

                                @if (in_array($shift['phase'], ['starting', 'active'], true))
                                    {{-- Says whether the coverage figure is settled or still filling. --}}
                                    <span class="shift-pool-now" style="left: {{ round($shift['elapsed'] * 100, 2) }}%" aria-hidden="true"></span>
                                @endif
                            </div>

                            <p class="shift-pool-footnote">
                                @if ($shift['phase'] === 'upcoming')
                                    No check-ins due yet.
                                @elseif ($shift['phase'] === 'starting')
                                    {{ $shift['minutes_in'] < 1 ? 'Just opened' : 'Started '.$shift['minutes_in'].' min ago' }} — coverage still filling.
                                @else
                                    {{ rtrim(rtrim(number_format($shift['coverage'], 1), '0'), '.') }}% clocked in{{ $shift['on_leave'] > 0 ? ', ' . number_format($shift['on_leave']) . ' on approved leave' : '' }}.
                                @endif
                            </p>

                            <a href="{{ route('schedules.index', ['date' => $overview['date'], 'view' => 'list']) }}" class="shift-pool-link">
                                View roster <x-icon name="chevron-right" />
                            </a>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($unstaffed->isNotEmpty())
                <ul class="shift-pool-quiet">
                    @foreach ($unstaffed as $shift)
                        <li>
                            <span class="shift-pool-quiet-name">{{ $shift['name'] }}</span>
                            <span class="shift-pool-quiet-span">{{ $shift['span_label'] }}</span>
                            <span class="shift-pool-quiet-note">
                                Nobody rostered{{ $shift['crosses_midnight'] ? ' · overnight' : '' }}.
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
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
                            <th scope="col">Status</th>
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
                                <td>{{ $shift['phase_label'] }}</td>
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
