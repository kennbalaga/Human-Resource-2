<nav class="organization-workspace-tabs" aria-label="Organization workspace">
    <a href="{{ route('employees.index') }}" @class(['active' => request()->routeIs('organization.index', 'employees.*')]) @if(request()->routeIs('organization.index', 'employees.*')) aria-current="page" @endif>
        <span class="organization-tab-icon"><x-icon name="users" /></span>
        <span><strong>Employees</strong><small>People, accounts, and reporting lines</small></span>
    </a>
    <a href="{{ route('organization.chart') }}" @class(['active' => request()->routeIs('organization.chart')]) @if(request()->routeIs('organization.chart')) aria-current="page" @endif>
        <span class="organization-tab-icon"><x-icon name="analytics" /></span>
        <span><strong>Org chart</strong><small>Reporting lines across the hospital</small></span>
    </a>
    <a href="{{ route('departments.index') }}" @class(['active' => request()->routeIs('departments.*')]) @if(request()->routeIs('departments.*')) aria-current="page" @endif>
        <span class="organization-tab-icon"><x-icon name="building" /></span>
        <span><strong>Departments</strong><small>Hospital units and workforce capacity</small></span>
    </a>
    <a href="{{ route('positions.index') }}" @class(['active' => request()->routeIs('positions.*')]) @if(request()->routeIs('positions.*')) aria-current="page" @endif>
        <span class="organization-tab-icon"><x-icon name="briefcase" /></span>
        <span><strong>Positions</strong><small>Approved roles and department alignment</small></span>
    </a>
</nav>
