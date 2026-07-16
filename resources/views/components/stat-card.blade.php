@props([
    'title',
    'value',
    'icon',
    'tone' => 'primary',
    'detail' => null,
    'actions' => [],
])

<article class="stat-card">
    <div class="stat-card-top">
        <span class="stat-icon stat-icon-{{ $tone }}">
            <x-icon :name="$icon" />
        </span>
        <x-dashboard-action-menu :label="'Options for '.$title" :items="$actions" />
    </div>
    <p class="stat-title">{{ $title }}</p>
    <div class="stat-value-row">
        <strong class="stat-value">{{ $value }}</strong>
        @if ($detail)
            <span class="stat-detail"><x-icon name="arrow-up" /> {{ $detail }}</span>
        @endif
    </div>
</article>
