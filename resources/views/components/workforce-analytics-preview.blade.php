@props(['preview'])

<section class="panel analytics-preview" id="analytics-preview" aria-labelledby="analytics-preview-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Workforce Analytics</p>
            <h2 id="analytics-preview-title">Key metrics at a glance</h2>
        </div>
        <span class="analytics-preview-range">{{ $preview['range_label'] }}</span>
    </div>

    <p class="analytics-preview-caption">
        <span>Month to date across {{ number_format($preview['headcount']) }} active {{ Str::plural('employee', $preview['headcount']) }} and {{ number_format($preview['workdays']) }} {{ Str::plural('workday', $preview['workdays']) }}</span>
        <span>A snapshot only — the full module carries the breakdowns and exports.</span>
    </p>

    @if (! $preview['tracked'])
        <div class="compact-empty-state analytics-preview-empty">
            <x-icon name="analytics" />
            <p>No attendance or leave has been recorded this month yet. Metrics appear here as the month fills in.</p>
        </div>
    @else
        <div class="analytics-metric-grid">
            @foreach ($preview['metrics'] as $metric)
                <article class="analytics-metric analytics-metric-{{ $metric['accent'] }}">
                    <p class="analytics-metric-label">{{ $metric['label'] }}</p>
                    <p class="analytics-metric-value">{{ $metric['display'] }}</p>

                    @if ($metric['share'] !== null)
                        <div
                            class="progress analytics-metric-meter"
                            role="progressbar"
                            aria-label="{{ $metric['label'] }}"
                            aria-valuenow="{{ $metric['share'] }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <div class="progress-bar" style="width: {{ $metric['share'] }}%"></div>
                        </div>
                    @endif

                    <p class="analytics-metric-detail">{{ $metric['detail'] }}</p>
                </article>
            @endforeach
        </div>

        <details class="analytics-table-view">
            <summary>View data table</summary>
            <div class="table-responsive">
                <table class="dashboard-table analytics-preview-table">
                    <caption class="visually-hidden">Workforce analytics preview for {{ $preview['range_label'] }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Metric</th>
                            <th scope="col">Value</th>
                            <th scope="col">Basis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['metrics'] as $metric)
                            <tr>
                                <th scope="row">{{ $metric['label'] }}</th>
                                <td>{{ $metric['display'] }}</td>
                                <td>{{ $metric['detail'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    <div class="analytics-preview-footer">
        <a href="{{ route('analytics.index', ['date_from' => $preview['from'], 'date_to' => $preview['to']]) }}" class="btn btn-primary analytics-preview-cta">
            <x-icon name="analytics" />
            View analytics
        </a>
        <span>Opens the full module on this same period.</span>
    </div>
</section>
