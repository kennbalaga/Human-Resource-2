@props([
    'label' => 'More options',
    'items' => [],
])

<div class="dropdown dashboard-action-menu">
    <button
        class="icon-button subtle"
        type="button"
        data-bs-toggle="dropdown"
        data-bs-auto-close="true"
        data-dashboard-action-menu
        aria-expanded="false"
        aria-label="{{ $label }}"
    >
        {{-- Vertical: the control sits at the end of a table row, and three dots
             laid across the row read as the start of more columns. --}}
        <x-icon name="more-vertical" />
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        @foreach($items as $item)
            <li>
                <a class="dropdown-item" {{ isset($item['attributes']) ? new \Illuminate\View\ComponentAttributeBag($item['attributes']) : '' }} href="{{ $item['url'] }}">
                    <x-icon :name="$item['icon'] ?? 'chevron-right'" />
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
        {{-- Anything that is not a plain link. The directory's archive and
             restore are POST forms with a confirmation on them, so they cannot
             be expressed as a url in the items array above. --}}
        {{ $slot }}
    </ul>
</div>
