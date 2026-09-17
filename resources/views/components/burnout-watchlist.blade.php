@props(['watchlist'])

{{-- Who to check on first. The Burnout Risk tab lists everyone; this names the
     few nearest the edge so they are seen without going looking. --}}
@php
    $tone = fn (string $level) => match ($level) {
        'high' => 'danger',
        'moderate' => 'warning',
        default => 'success',
    };
@endphp

<aside class="panel burnout-watchlist" id="burnout-watchlist" aria-labelledby="burnout-watchlist-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Last {{ intdiv((int) config('burnout.window_days'), 7) }} weeks</p>
            <h2 id="burnout-watchlist-title">Closest to burnout</h2>
        </div>
        <span class="today-exceptions-asof">{{ number_format($watchlist['assessed']) }} assessed</span>
    </div>

    <div class="burnout-watchlist-counts">
        <a href="{{ route('analytics.burnout-risk', ['level' => 'high']) }}" class="burnout-watchlist-count is-high">
            <b>{{ number_format($watchlist['high']) }}</b><span>High</span>
        </a>
        <a href="{{ route('analytics.burnout-risk', ['level' => 'moderate']) }}" class="burnout-watchlist-count is-moderate">
            <b>{{ number_format($watchlist['moderate']) }}</b><span>Moderate</span>
        </a>
        <a href="{{ route('analytics.burnout-risk', ['trend' => 'rising']) }}" class="burnout-watchlist-count">
            <b>{{ number_format($watchlist['rising']) }}</b><span>Rising</span>
        </a>
    </div>

    <section class="exception-block">
        @if (count($watchlist['people']) === 0)
            <p class="exception-empty">Nobody you supervise has been assessed yet.</p>
        @else
            <ol class="exception-list burnout-watchlist-list">
                @foreach ($watchlist['people'] as $person)
                    <li>
                        <a class="exception-item" href="{{ route('analytics.burnout-risk', array_filter(['department_id' => $person['department_id']])) }}">
                            <span class="exception-item-main">
                                <strong>{{ $person['name'] }}</strong>
                                <span class="exception-item-meta">
                                    {{ $person['driver'] ?? 'Nothing notable' }}@if ($person['department']) · {{ $person['department'] }}@endif
                                </span>
                            </span>
                            <span class="burnout-watchlist-score">
                                <span class="staff-chip staff-chip-{{ $tone($person['level']) }}">{{ number_format($person['score']) }}</span>
                                @if ($person['trend'] === 'rising')
                                    <small class="is-rising" title="Up {{ number_format(abs($person['change']), 1) }} points">&#9650;</small>
                                @elseif ($person['trend'] === 'easing')
                                    <small class="is-easing" title="Down {{ number_format(abs($person['change']), 1) }} points">&#9660;</small>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>

            @if ($watchlist['at_risk_beyond_list'] > 0)
                <a class="exception-more" href="{{ route('analytics.burnout-risk') }}">
                    {{ number_format($watchlist['at_risk_beyond_list']) }} more at moderate or high risk <x-icon name="chevron-right" />
                </a>
            @endif
        @endif
    </section>

    <a href="{{ route('analytics.burnout-risk') }}" class="panel-footer-link">View everyone <x-icon name="chevron-right" /></a>
</aside>
