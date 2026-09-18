{{-- The two Workforce Analytics views. The sidebar's one "Workforce Analytics"
     link stays lit on both, because it matches analytics.*; this strip is how
     you move between them. The department filter travels with you. --}}
@php
    $tabQuery = array_filter(['department_id' => request('department_id')]);
@endphp
<nav class="page-tabs analytics-tabs" aria-label="Workforce analytics views">
    <a
        href="{{ route('analytics.index', $tabQuery) }}"
        data-analytics-tab="overview"
        @class(['page-tab', 'analytics-tab', 'is-active' => request()->routeIs('analytics.index')])
        @if (request()->routeIs('analytics.index')) aria-current="page" @endif
    >
        <x-page-tab-label icon="analytics" title="Overview" description="Attendance, labor hours, leave use and schedule coverage" />
    </a>
    {{-- HR and department heads only. A system administrator can read the
         overview but sees nobody's burnout risk but their own. --}}
    @can('burnout.view-workforce')
        <a
            href="{{ route('analytics.burnout-risk', $tabQuery) }}"
            @class(['page-tab', 'analytics-tab', 'is-active' => request()->routeIs('analytics.burnout-risk')])
            @if (request()->routeIs('analytics.burnout-risk')) aria-current="page" @endif
        >
            <x-page-tab-label icon="alert" title="Burnout Risk" description="Workload and fatigue signals, employee by employee" />
        </a>
    @endcan
</nav>
