{{-- The employee record slides in over the directory so the list, its filters,
     and the scroll position all survive a look at somebody's profile.

     The View button keeps its real href throughout: a middle-click, a copied
     link, a failed fetch, or a browser running without our scripts all still
     land on the full profile page. --}}
<div class="offcanvas offcanvas-end employee-panel" tabindex="-1" id="employeePanel" aria-labelledby="employeePanelLabel">
    <div class="offcanvas-header employee-panel-header">
        <div>
            <p class="panel-kicker">Organization · Employees</p>
            <h2 class="offcanvas-title" id="employeePanelLabel">Employee profile</h2>
        </div>
        <div class="employee-panel-header-actions">
            <a class="btn btn-light btn-sm" data-employee-panel-full href="{{ route('employees.index') }}">Open full page</a>
            <button class="btn-close" type="button" data-bs-dismiss="offcanvas" aria-label="Close employee profile"></button>
        </div>
    </div>
    {{-- This placeholder is also the loading state: the script keeps a copy of
         it and puts it back between records. --}}
    <div class="offcanvas-body employee-panel-body" data-employee-panel-body aria-live="polite">
        <p class="employee-panel-status"><span class="employee-panel-spinner" aria-hidden="true"></span> Loading employee profile…</p>
    </div>
</div>
