@props([
    'title',
    'value',
    'icon',
    'tone' => 'primary',
    'detail' => null,
])

<article class="stat-card">
    <div class="stat-card-top">
        <span class="stat-icon stat-icon-{{ $tone }}">
            <x-icon :name="$icon" />
        </span>
        <button class="icon-button subtle" type="button" aria-label="More options">
            <x-icon name="more" />
        </button>
    </div>
    <p class="stat-title">{{ $title }}</p>
    <div class="stat-value-row">
        <strong class="stat-value">{{ $value }}</strong>
        @if ($detail)
            <span class="stat-detail"><x-icon name="arrow-up" /> {{ $detail }}</span>
        @endif
    </div>
</article>
