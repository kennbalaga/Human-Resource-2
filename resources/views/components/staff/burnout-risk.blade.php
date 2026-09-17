@props(['burnout'])

@php
    $windowDays = (int) config('burnout.window_days');
    $windowLabel = $windowDays % 7 === 0
        ? 'Last '.intdiv($windowDays, 7).' weeks'
        : 'Last '.$windowDays.' days';
    $tone = match ($burnout['level']) {
        'high' => 'danger',
        'moderate' => 'warning',
        default => 'success',
    };
    $previousLabel = 'the previous '.($windowDays % 7 === 0 ? intdiv($windowDays, 7).' weeks' : $windowDays.' days');
    $change = abs((float) ($burnout['change'] ?? 0));
    $trend = match ($burnout['trend']) {
        'rising' => ['arrow-up', 'Up '.number_format($change, 1).' points from '.$previousLabel],
        'easing' => ['arrow-down', 'Down '.number_format($change, 1).' points from '.$previousLabel],
        'steady' => ['trend', 'About the same as '.$previousLabel],
        default => ['trend', 'No earlier period to compare with yet'],
    };
@endphp

<section class="panel staff-burnout" id="burnout-risk" aria-labelledby="burnout-risk-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">{{ $windowLabel }}</p>
            <h2 id="burnout-risk-title">My workload &amp; rest</h2>
        </div>
        <span class="staff-chip staff-chip-{{ $tone }}">Burnout risk: {{ $burnout['level_label'] }}</span>
    </div>

    <div class="staff-burnout-body">
        <div class="staff-burnout-score">
            <p class="staff-hero-figure">
                <b>{{ number_format($burnout['score']) }}</b>
                <span>out of 100</span>
            </p>
            <div
                class="staff-burnout-meter is-{{ $burnout['level'] }}"
                role="meter"
                aria-label="Burnout risk score"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ $burnout['score'] }}"
            >
                <i style="width: {{ max(2, min(100, $burnout['score'])) }}%"></i>
            </div>
            <p class="staff-burnout-trend"><x-icon :name="$trend[0]" /> {{ $trend[1] }}</p>
        </div>

        <div class="staff-burnout-detail">
            <h3>What is adding to it</h3>
            @if (count($burnout['drivers']) > 0)
                <ul class="staff-burnout-drivers">
                    @foreach ($burnout['drivers'] as $driver)
                        <li>
                            <span>{{ $driver['summary'] }}</span>
                            <b>+{{ number_format($driver['points'], 1) }}</b>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="staff-burnout-empty">Nothing in your recent hours, rest or leave is adding to it.</p>
            @endif
            <p class="staff-burnout-suggestion">{{ $burnout['suggestion']['text'] }}</p>
        </div>
    </div>

    <p class="staff-burnout-note">{{ $burnout['disclaimer'] }}</p>

    @if ($burnout['suggestion']['route'])
        <a href="{{ route($burnout['suggestion']['route']) }}" class="panel-footer-link">{{ $burnout['suggestion']['action'] }} <x-icon name="chevron-right" /></a>
    @endif
</section>
