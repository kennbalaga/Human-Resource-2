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

{{-- Label first, then the figure, with the icon held out to the right: the card
     is read for its number, so the number is the thing with nothing beside it.
     The whole card is the link, which is why there is no separate chevron. --}}
<a class="stat-card" href="{{ $href }}" aria-label="Open {{ $title }}">
    <div class="stat-card-top">
        <p class="stat-title">{{ $title }}</p>
        <span class="stat-icon">
            <x-icon :name="$icon" />
        </span>
    </div>
    <div class="stat-value-row">
        <strong class="stat-value">{{ $value }}</strong>
    </div>
    <div class="stat-detail-row">
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

