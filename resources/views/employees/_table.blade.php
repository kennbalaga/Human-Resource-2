@php
    /*
     * The kicker states what the list below is actually showing rather than
     * repeating the panel's name: with three filters on the page, "6 employees"
     * on its own does not say six out of what.
     */
    $scopeDepartment = ($filters['department_id'] ?? null)
        ? (collect($departments)->firstWhere('id', (int) $filters['department_id'])?->name ?? 'All departments')
        : 'All departments';
    $scopeStatus = ($filters['status'] ?? null)
        ? str($filters['status'])->replace('_', ' ')->title()->toString()
        : 'All statuses';
@endphp

<div class="panel-header">
    <div>
        <p class="panel-kicker">{{ $scopeDepartment }} · {{ $scopeStatus }}</p>
        <h2>{{ number_format($employees->total()) }} {{ str('employee')->plural($employees->total()) }}</h2>
    </div>
    @if (filled($filters['search'] ?? null))
        <span class="status-badge status-primary"><span class="status-dot"></span>Filtered by “{{ $filters['search'] }}”</span>
    @endif
</div>
<div class="table-responsive">
    <table class="dashboard-table organization-table table-stack">
        {{-- View, Edit and Archive used to sit in the last column as three
             buttons, which needed 19% of the table to stay on one line. They are
             one overflow menu now, so that column is down to the width of a
             32px control and the 13% goes back to the columns that were being
             squeezed -- the name and its email most of all. --}}
        <colgroup><col style="width: 26%"><col style="width: 14%"><col style="width: 15%"><col style="width: 15%"><col style="width: 13%"><col style="width: 11%"><col style="width: 6%"></colgroup>
        <thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Position</th><th>Supervisor</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
            @forelse ($employees as $employee)
                <tr>
                    {{-- The name opens the record. It was plain text beside a
                         View button that did the same thing, which asked the
                         reader to travel to the end of the row for the action
                         the row is named after. --}}
                    <td data-label="Employee"><div class="employee-cell"><span class="avatar avatar-table">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</span><div class="employee-cell-copy"><a class="employee-cell-link" data-employee-panel href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a><span>{{ $employee->user?->email ?? 'No linked email' }}</span></div></div></td>
                    <td data-label="Employee ID"><span class="employee-number tabular">{{ $employee->employee_number }}</span></td>
                    <td data-label="Department">{{ $employee->department?->name ?? 'Unassigned' }}</td>
                    <td data-label="Position">{{ $employee->position?->title ?? 'Unassigned' }}</td>
                    <td data-label="Supervisor">{{ $employee->supervisor?->full_name ?? 'None' }}</td>
                    <td data-label="Status">
                        <div class="organization-status-cell">
                            <x-status-badge :status="str($employee->employment_status)->replace('_', ' ')" />
                            {{-- Shown beside the employment status rather than
                                 in place of it: archived says where the record
                                 is kept, not what the person's employment
                                 was. --}}
                            @if($employee->isArchived())<x-status-badge status="archived" />@endif
                        </div>
                    </td>
                    <td class="organization-row-actions-cell">
                        <x-dashboard-action-menu :label="'Actions for '.$employee->full_name" :items="array_values(array_filter([
                            ['label' => 'View employee', 'url' => route('employees.show', $employee), 'icon' => 'users', 'attributes' => ['data-employee-panel' => true]],
                            $canManage ? ['label' => 'Edit employee', 'url' => route('employees.edit', $employee), 'icon' => 'edit', 'attributes' => ['data-employee-edit' => true]] : null,
                        ]))">
                            {{-- Archive is the second half of a termination, so it
                                 appears only once the first half has happened. On
                                 everybody still employed the item is absent
                                 rather than disabled: a greyed-out control invites
                                 the click that the rule exists to prevent. --}}
                            @if($canManage && $employee->isArchived())
                                <li><form method="POST" action="{{ route('employees.restore', $employee) }}" data-confirm="Restore {{ $employee->full_name }} to the directory? They stay out of the archive until you archive them again." data-confirm-button="Restore record">@csrf<button class="dropdown-item" type="submit"><x-icon name="repeat" /><span>Restore to directory</span></button></form></li>
                            @elseif($canManage && $employee->canBeArchived())
                                <li><form method="POST" action="{{ route('employees.archive', $employee) }}" data-confirm="Archive {{ $employee->full_name }}? The record is kept in full and can be restored at any time." data-confirm-button="Archive record" data-confirm-tone="caution">@csrf<button class="dropdown-item" type="submit"><x-icon name="inbox" /><span>Archive record</span></button></form></li>
                            @endif
                        </x-dashboard-action-menu>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-table-cell"><x-icon name="users" /><strong>No matching employees</strong><span>Adjust the filters or add a new employee.</span></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($employees->hasPages())<div class="report-pagination">{{ $employees->onEachSide(1)->links('pagination::bootstrap-5') }}</div>@endif
