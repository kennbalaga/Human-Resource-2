@props([
    'title',
    'value',
    'icon',
    'tone' => 'primary',
    'detail' => null,
    'href' => '#',
])

<a class="stat-card" href="{{ $href }}" aria-label="Open {{ $title }}">
    <div class="stat-card-top">
        <span class="stat-icon stat-icon-{{ $tone }}">
            <x-icon :name="$icon" />
        </span>
        <span class="stat-card-link-icon" aria-hidden="true"><x-icon name="chevron-right" /></span>
    </div>
    <p class="stat-title">{{ $title }}</p>
    <div class="stat-value-row">
        <strong class="stat-value">{{ $value }}</strong>
        @if ($detail)
            <span class="stat-detail"><x-icon name="arrow-up" /> {{ $detail }}</span>
        @endif
    </div>
</a>
