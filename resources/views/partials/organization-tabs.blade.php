<nav class="page-tabs organization-workspace-tabs" aria-label="Organization workspace">
    <a href="{{ route('employees.index') }}" @class(['page-tab', 'active' => request()->routeIs('organization.index', 'employees.*')]) @if(request()->routeIs('organization.index', 'employees.*')) aria-current="page" @endif>
        <x-page-tab-label icon="users" title="Employees" description="People, accounts, and reporting lines" />
    </a>
    <a href="{{ route('departments.index') }}" @class(['page-tab', 'active' => request()->routeIs('departments.*')]) @if(request()->routeIs('departments.*')) aria-current="page" @endif>
        <x-page-tab-label icon="building" title="Departments" description="Hospital units and workforce capacity" />
    </a>
    <a href="{{ route('positions.index') }}" @class(['page-tab', 'active' => request()->routeIs('positions.*')]) @if(request()->routeIs('positions.*')) aria-current="page" @endif>
        <x-page-tab-label icon="briefcase" title="Positions" description="Approved roles and department alignment" />
    </a>
    <a href="{{ route('rooms.index') }}" @class(['page-tab', 'active' => request()->routeIs('rooms.*')]) @if(request()->routeIs('rooms.*')) aria-current="page" @endif>
        <x-page-tab-label icon="layers" title="Rooms" description="Theatres, wards, and clinic rooms" />
    </a>
</nav>
