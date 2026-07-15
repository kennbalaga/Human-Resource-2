@props([
    'href' => '#',
    'icon',
    'active' => false,
    'badge' => null,
])

<a href="{{ $href }}" {{ $attributes->class(['sidebar-link', 'active' => $active]) }} @if($active) aria-current="page" @endif>
    <span class="sidebar-link-icon"><x-icon :name="$icon" /></span>
    <span class="sidebar-link-label">{{ $slot }}</span>
    @if ($badge)
        <span class="sidebar-link-badge">{{ $badge }}</span>
    @endif
</a>
