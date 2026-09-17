{{-- The two Workforce Analytics views. The sidebar's one "Workforce Analytics"
     link stays lit on both, because it matches analytics.*; this strip is how
     you move between them. The department filter travels with you. --}}
@php
    $tabQuery = array_filter(['department_id' => request('department_id')]);
@endphp
<nav class="analytics-tabs" aria-label="Workforce analytics views">
    <a
        href="{{ route('analytics.index', $tabQuery) }}"
        data-analytics-tab="overview"
        @class(['analytics-tab', 'is-active' => request()->routeIs('analytics.index')])
        @if (request()->routeIs('analytics.index')) aria-current="page" @endif
    >
        <x-icon name="analytics" /> Overview
    </a>
    {{-- HR and department heads only. A system administrator can read the
         overview but sees nobody's burnout risk but their own. --}}
    @can('burnout.view-workforce')
        <a
            href="{{ route('analytics.burnout-risk', $tabQuery) }}"
            @class(['analytics-tab', 'is-active' => request()->routeIs('analytics.burnout-risk')])
            @if (request()->routeIs('analytics.burnout-risk')) aria-current="page" @endif
        >
            <x-icon name="alert" /> Burnout Risk
        </a>
    @endcan
</nav>
