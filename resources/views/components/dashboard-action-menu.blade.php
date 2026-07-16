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
        <x-icon name="more" />
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        @foreach($items as $item)
            <li>
                <a class="dropdown-item" href="{{ $item['url'] }}">
                    <x-icon :name="$item['icon'] ?? 'chevron-right'" />
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
