{{-- The employee record slides in over the directory so the list, its filters,
     and the scroll position all survive a look at somebody's profile. This is
     the only place a profile is shown; there is no page behind it.

     The View button keeps its real href throughout, because that URL is still
     the record's address — following it for real just bounces back here with
     the panel already open, which is what makes a copied link or a global
     search result still work. `data-employee-panel-initial` is how it arrives
     that way. --}}
@php($initialPanelEmployee = request()->integer('employee'))
<div
    class="offcanvas offcanvas-end employee-panel"
    tabindex="-1"
    id="employeePanel"
    aria-labelledby="employeePanelLabel"
    @if($initialPanelEmployee) data-employee-panel-initial="{{ route('employees.show', $initialPanelEmployee) }}" @endif
>
    <div class="offcanvas-header employee-panel-header">
        <div>
            <p class="panel-kicker">Organization · Employees</p>
            <h2 class="offcanvas-title" id="employeePanelLabel">Employee profile</h2>
        </div>
        <div class="employee-panel-header-actions">
            <button class="btn-close" type="button" data-bs-dismiss="offcanvas" aria-label="Close employee profile"></button>
        </div>
    </div>
    {{-- This placeholder is also the loading state: the script keeps a copy of
         it and puts it back between records. --}}
    <div class="offcanvas-body employee-panel-body" data-employee-panel-body aria-live="polite">
        <p class="employee-panel-status"><span class="employee-panel-spinner" aria-hidden="true"></span> Loading employee profile…</p>
    </div>
</div>
