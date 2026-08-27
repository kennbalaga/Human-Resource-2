@props(['name'])

<svg {{ $attributes->merge(['class' => 'ui-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="7" height="7" rx="2" />
            <rect x="14" y="3" width="7" height="7" rx="2" />
            <rect x="3" y="14" width="7" height="7" rx="2" />
            <rect x="14" y="14" width="7" height="7" rx="2" />
            @break
        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
            @break
        @case('building')
            <path d="M3 21h18M6 21V4a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v17M15 8h3a1 1 0 0 1 1 1v12" />
            <path d="M9 7h2M9 11h2M9 15h2" />
            @break
        @case('briefcase')
            <rect x="3" y="7" width="18" height="13" rx="2" />
            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2" />
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M16 3v4M8 3v4M3 10h18" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-4-4" />
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16" />
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18" />
            @break
        @case('chevron-down')
            <path d="m7 10 5 5 5-5" />
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('chevron-up-down')
            <path d="m8 9 4-4 4 4M16 15l-4 4-4-4" />
            @break
        @case('chevrons-left')
            <path d="m11 17-5-5 5-5M18 17l-5-5 5-5" />
            @break
        @case('more-vertical')
            <circle cx="12" cy="5" r="1" fill="currentColor" stroke="none" />
            <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
            <circle cx="12" cy="19" r="1" fill="currentColor" stroke="none" />
            @break
        @case('logout')
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.1A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.18.38.5.7.9.88.28.13.58.2.9.2h.1v4h-.1a1.7 1.7 0 0 0-1.8.92Z" />
            @break
        @case('check-circle')
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12 2.5 2.5L16 9" />
            @break
        @case('circle')
            <circle cx="12" cy="12" r="9" />
            @break
        @case('arrow-up')
            <path d="m18 15-6-6-6 6" />
            @break
        @case('hospital')
            <path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M9 21v-4h6v4M9 8h6M12 5v6" />
            @break
        @case('more')
            <circle cx="5" cy="12" r="1" fill="currentColor" stroke="none" />
            <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
            <circle cx="19" cy="12" r="1" fill="currentColor" stroke="none" />
            @break
        @case('map-pin')
            <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" />
            <circle cx="12" cy="10" r="2.5" />
            @break
        @case('mail')
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3.5 7 8.5 6 8.5-6" />
            @break
        @case('phone')
            <path d="M7 3h3l1.5 4-2 1.5a12 12 0 0 0 6 6L17 12.5 21 14v3a2 2 0 0 1-2.2 2A17 17 0 0 1 5 5.2 2 2 0 0 1 7 3Z" />
            @break
        @case('log-in')
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3" />
            @break
        @case('log-out')
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M14 17l5-5-5-5M19 12H8" />
            @break
        @case('report')
            <path d="M5 3h11l3 3v15H5zM14 3v5h5M8 12h8M8 16h8" />
            @break
        @case('download')
            <path d="M12 3v12M7 10l5 5 5-5M5 21h14" />
            @break
        @case('refresh')
            <path d="M20 6v5h-5M4 18v-5h5M18.5 9A7 7 0 0 0 6 6.5L4 11M5.5 15A7 7 0 0 0 18 17.5l2-4.5" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('repeat')
            <path d="m17 1 4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3" />
            @break
        @case('edit')
            <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z" />
            @break
        @case('trash')
            <path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v5M14 11v5" />
            @break
        @case('timesheet')
            <path d="M6 3h12a2 2 0 0 1 2 2v16H4V5a2 2 0 0 1 2-2Z" />
            <path d="M8 3v4h8V3M8 11h8M8 15h5" />
            @break
        @case('leave')
            <path d="M4 19c4-8 8-12 16-14-1 8-5 12-13 13" />
            <path d="M4 21c2-5 6-9 12-12" />
            @break
        @case('paperclip')
            <path d="m21 11.5-8.5 8.5a6 6 0 0 1-8.5-8.5l9-9a4 4 0 0 1 5.7 5.7l-9 9a2 2 0 0 1-2.9-2.8l8.5-8.5" />
            @break
        @case('analytics')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
            @break
        @case('plug')
            <path d="m8 12 4 4 4-4M9 3v5M15 3v5M6 8h12v2a6 6 0 0 1-12 0Z" />
            <path d="M12 16v5" />
            @break
        @case('ai')
            <path d="M12 3a5 5 0 0 0-5 5c0 .7.1 1.3.4 1.9A4 4 0 0 0 9 17.5V21h6v-3.5a4 4 0 0 0 1.6-7.6A5 5 0 0 0 12 3Z" />
            <path d="M9 9h.01M15 9h.01M10 13h4" />
            @break
        @case('lock')
            <rect x="4" y="10.5" width="16" height="10" rx="2.5" />
            <path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7" />
            @break
        @case('shield')
            <path d="M12 3 20 6v6c0 5-3.4 8-8 9-4.6-1-8-4-8-9V6Z" />
            <path d="m9 12 2 2 4-4" />
            @break
        @case('fingerprint')
            <path d="M12 11v2a9 9 0 0 1-1.5 5" />
            <path d="M8.5 9.5a3.5 3.5 0 0 1 7 0V13c0 1.2.2 2.4.6 3.5" />
            <path d="M5.5 13v-2a6.5 6.5 0 0 1 10-5.5" />
            <path d="M18.5 11v2c0 .9.1 1.8.3 2.7" />
            <path d="M12 15v1a12 12 0 0 1-.6 3.7" />
            @break
        @case('alert')
            <path d="M12 3.8 2.9 19.2a1 1 0 0 0 .9 1.5h16.4a1 1 0 0 0 .9-1.5Z" />
            <path d="M12 9.5v4M12 17h.01" />
            @break
        @case('trend')
            <path d="M3 17l6-6 4 4 8-8" />
            <path d="M15 7h6v6" />
            @break
        @case('moon')
            <path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z" />
            @break
        @case('sun')
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42" />
            @break
        @default
            <circle cx="12" cy="12" r="9" />
    @endswitch
</svg>
