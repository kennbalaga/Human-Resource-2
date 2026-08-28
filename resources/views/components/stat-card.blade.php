@props([
    'title',
    'value',
    'icon',
    'detail' => null,
    /*
     * 'up' | 'down' | null. The arrow used to be unconditional, so "Ready for
     * duty", "Operational units" and even "0 new this month" all rendered as
     * upward movement. A card only earns an arrow when something was actually
     * compared against something else; everything else states its detail plainly.
     */
    'trend' => null,
    /* What the arrow is claiming, for the reader who cannot see its direction. */
    'trendLabel' => null,
    'href' => '#',
])

<a class="stat-card" href="{{ $href }}" aria-label="Open {{ $title }}">
    <div class="stat-card-top">
        <span class="stat-icon">
            <x-icon :name="$icon" />
        </span>
        <span class="stat-card-link-icon" aria-hidden="true"><x-icon name="chevron-right" /></span>
    </div>
    <p class="stat-title">{{ $title }}</p>
    <div class="stat-value-row">
        <strong class="stat-value">{{ $value }}</strong>
        @if ($detail)
            <span
                @class([
                    'stat-detail',
                    'stat-detail-up' => $trend === 'up',
                    'stat-detail-down' => $trend === 'down',
                ])
                @if ($trendLabel) title="{{ $trendLabel }}" @endif
            >
                @if ($trend)
                    <x-icon :name="$trend === 'up' ? 'arrow-up' : 'arrow-down'" />
                @endif
                {{ $detail }}
                @if ($trendLabel)
                    <span class="visually-hidden">— {{ $trendLabel }}</span>
                @endif
            </span>
        @endif
    </div>
</a>
