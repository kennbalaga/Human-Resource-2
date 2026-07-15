@props([
    'href' => '#',
    'icon',
    'active' => false,
    'badge' => null,
])

@php($sidebarLabel = trim(strip_tags((string) $slot)))

<a href="{{ $href }}" title="{{ $sidebarLabel }}" {{ $attributes->class(['sidebar-link', 'active' => $active]) }} @if($active) aria-current="page" @endif>
    <span class="sidebar-link-icon"><x-icon :name="$icon" /></span>
    <span class="sidebar-link-label">{{ $slot }}</span>
    @if ($badge)
        <span class="sidebar-link-badge">{{ $badge }}</span>
    @endif
</a>
