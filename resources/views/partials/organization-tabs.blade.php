<nav class="page-tabs organization-workspace-tabs" aria-label="Organization workspace">
    <a href="{{ route('employees.index') }}" @class(['page-tab', 'active' => request()->routeIs('organization.index', 'employees.*')]) @if(request()->routeIs('organization.index', 'employees.*')) aria-current="page" @endif>
        <x-page-tab-label icon="users" title="Employees" description="People, accounts, and reporting lines" />
    </a>
    <a href="{{ route('departments.index') }}" @class(['page-tab', 'active' => request()->routeIs('departments.*')]) @if(request()->routeIs('departments.*')) aria-current="page" @endif>
        <x-page-tab-label icon="building" title="Departments" description="Operational units and their heads" />
    </a>
    <a href="{{ route('positions.index') }}" @class(['page-tab', 'active' => request()->routeIs('positions.*')]) @if(request()->routeIs('positions.*')) aria-current="page" @endif>
        <x-page-tab-label icon="briefcase" title="Positions" description="Defined roles and their codes" />
    </a>
</nav>
