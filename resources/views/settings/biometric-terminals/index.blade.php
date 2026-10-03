@extends('layouts.app')

@section('title', 'Biometric Terminals')

@php
    /*
     * The filters that survived validation, stripped of blanks. Used for the
     * export link and the "clear" affordance so both agree with the table.
     * request()->query() is deliberately not reused: it carries `page`, and an
     * export of "page 4" is not a thing.
     */
    $appliedFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
@endphp

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">System administration</p>
            <h1>Biometric Terminals</h1>
            <p>
                Register the fingerprint terminal and reserve the PIN it knows each person by. A PIN is the
                employee&rsquo;s own record number, so nothing has to be invented or kept in step.
            </p>
        </div>
        @if ($device !== null)
            <div class="audit-heading-actions">
                <a
                    class="btn btn-primary dashboard-action"
                    data-download
                    href="{{ route('settings.biometric-terminals.export', $appliedFilters) }}"
                >
                    <x-icon name="download" />
                    Export roster CSV
                </a>
            </div>
        @endif
    </section>

    {{-- Panel one: the terminals. Enrolments hang off a device and the roster
         needs one selected, so the device list has to be on screen anyway. --}}
    <section class="panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">Registered hardware</p>
                <h2>Terminals</h2>
            </div>
        </div>

        @if ($devices->isEmpty())
            <p class="bio-empty-note">
                No terminal is registered yet. The punch endpoint refuses any serial it does not know, so
                the bridge agent cannot post attendance until one is added below.
            </p>
        @else
            <div class="table-responsive">
                <table class="dashboard-table workforce-table table-stack">
                    <caption class="visually-hidden">
                        Registered biometric terminals, their serial numbers, the office they stand in and
                        whether they may currently post attendance.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Terminal</th>
                            <th scope="col">Serial</th>
                            <th scope="col">Office</th>
                            <th scope="col">Last seen</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($devices as $terminal)
                            <tr @class(['is-selected' => $device !== null && $terminal->id === $device->id])>
                                <td data-label="Terminal">
                                    <strong>{{ $terminal->name }}</strong>
                                    <small class="d-block text-muted">{{ $terminal->code }} &middot; {{ $terminal->provider }}</small>
                                </td>
                                <td data-label="Serial"><code>{{ $terminal->serial_number ?? '—' }}</code></td>
                                <td data-label="Office">{{ $terminal->officeLocation?->name ?? '—' }}</td>
                                <td data-label="Last seen">{{ $terminal->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                                <td data-label="Status">
                                    <span @class(['bio-pill', 'is-captured' => $terminal->is_active, 'is-retired' => ! $terminal->is_active])>
                                        {{ $terminal->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td data-label="Actions">
                                    <div class="bio-row-actions">
                                        @unless ($device !== null && $terminal->id === $device->id)
                                            <a class="btn btn-light btn-sm" href="{{ route('settings.biometric-terminals.index', ['device' => $terminal->id]) }}">
                                                Show roster
                                            </a>
                                        @endunless
                                        <form method="POST" action="{{ route('settings.biometric-devices.update', $terminal) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="office_location_id" value="{{ $terminal->office_location_id }}">
                                            <input type="hidden" name="code" value="{{ $terminal->code }}">
                                            <input type="hidden" name="name" value="{{ $terminal->name }}">
                                            <input type="hidden" name="provider" value="{{ $terminal->provider }}">
                                            <input type="hidden" name="serial_number" value="{{ $terminal->serial_number }}">
                                            <input type="hidden" name="is_active" value="{{ $terminal->is_active ? 0 : 1 }}">
                                            <button class="btn btn-light btn-sm" type="submit">
                                                {{ $terminal->is_active ? 'Deactivate' : 'Reactivate' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.biometric-devices.store') }}" class="bio-device-form">
            @csrf
            <label>
                <span>Terminal name</span>
                <input name="name" value="{{ old('name') }}" placeholder="Main Entrance Terminal" required>
            </label>
            <label>
                <span>Code</span>
                <input name="code" value="{{ old('code') }}" placeholder="djnrmhs-main-entrance" required>
            </label>
            <label>
                <span>Serial number</span>
                <input name="serial_number" value="{{ old('serial_number') }}" placeholder="QME2261300147" required>
                <small>Exactly as the device reports it. The bridge agent signs as this serial.</small>
            </label>
            <label>
                <span>Office</span>
                <select name="office_location_id" required>
                    @foreach ($offices as $office)
                        <option value="{{ $office->id }}" @selected(old('office_location_id') == $office->id)>{{ $office->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Provider</span>
                <select name="provider">
                    <option value="zkteco" @selected(old('provider', 'zkteco') === 'zkteco')>ZKTeco</option>
                </select>
            </label>
            <div class="audit-filter-actions">
                <span aria-hidden="true">&nbsp;</span>
                <div><button class="btn btn-primary" type="submit">Register terminal</button></div>
            </div>
        </form>
    </section>

    {{-- Panel two: outstanding physical work. Rendered only when there is any,
         because an empty "attention" box trains people to ignore the filled
         one. --}}
    @if ($templatesToRemove->isNotEmpty() || $nonConformingPins->isNotEmpty())
        <section class="panel bio-attention-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Needs a person</p>
                    <h2>Attention</h2>
                </div>
            </div>

            @if ($templatesToRemove->isNotEmpty())
                <h3 class="bio-attention-heading">Templates still on the terminal</h3>
                <p class="bio-empty-note">
                    These people have left, and their punches are already refused — but their fingerprint is
                    still stored on the device. Deleting them here does not reach the terminal, so remove
                    them at the device first, then retire the enrolment below.
                </p>
                <ul class="bio-attention-list">
                    @foreach ($templatesToRemove as $enrollment)
                        <li>
                            <span>
                                <strong>{{ $enrollment->employee?->full_name ?? 'Employee #'.$enrollment->employee_id }}</strong>
                                &middot; PIN {{ $enrollment->external_user_id }}
                                &middot; {{ $enrollment->employee?->employment_status }}
                            </span>
                            <form method="POST" action="{{ route('settings.biometric-enrollments.update', $enrollment) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="deactivate">
                                <button class="btn btn-light btn-sm" type="submit">Removed from device</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($nonConformingPins->isNotEmpty())
                <h3 class="bio-attention-heading">PINs that are not the employee record number</h3>
                <p class="bio-empty-note">
                    These rows were created by hand before this page existed. A bulk assignment would collide
                    with them, so it refuses until they are retired or corrected.
                </p>
                <ul class="bio-attention-list">
                    @foreach ($nonConformingPins as $enrollment)
                        <li>
                            <span>
                                <strong>{{ $enrollment->employee?->full_name ?? 'Employee #'.$enrollment->employee_id }}</strong>
                                &middot; holds PIN {{ $enrollment->external_user_id }},
                                expected {{ $enrollment->employee_id }}
                            </span>
                            <form method="POST" action="{{ route('settings.biometric-enrollments.update', $enrollment) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="deactivate">
                                <button class="btn btn-light btn-sm" type="submit">Retire this enrolment</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- Panel three: the roster itself. --}}
    @if ($device === null)
        <section class="panel">
            <p class="bio-empty-note">Register a terminal above to see who still needs enrolling on it.</p>
        </section>
    @else
        <section class="audit-summary-grid">
            <article class="audit-summary-card">
                <span class="audit-summary-icon"><x-icon name="users" /></span>
                <p class="audit-summary-label">Staff in scope</p>
                <strong class="audit-summary-value">{{ number_format($summary['people']) }}</strong>
                <small>{{ $appliedFilters === [] ? 'Active, not archived' : 'Matching the current filters' }}</small>
            </article>
            <article class="audit-summary-card">
                <span class="audit-summary-icon info"><x-icon name="fingerprint" /></span>
                <p class="audit-summary-label">Fingerprint captured</p>
                <strong class="audit-summary-value">{{ number_format($summary['captured']) }}</strong>
                <small>The terminal knows these people</small>
            </article>
            <article @class(['audit-summary-card', 'is-attention' => $summary['assigned'] > 0])>
                <span class="audit-summary-icon danger"><x-icon name="alert" /></span>
                <p class="audit-summary-label">Awaiting capture</p>
                <strong class="audit-summary-value">{{ number_format($summary['assigned']) }}</strong>
                <small>PIN reserved, fingerprint not taken yet</small>
            </article>
            <article class="audit-summary-card">
                <span class="audit-summary-icon"><x-icon name="edit" /></span>
                <p class="audit-summary-label">No PIN yet</p>
                <strong class="audit-summary-value">{{ number_format($summary['unassigned']) }}</strong>
                <small>Not reserved on this terminal</small>
            </article>
        </section>

        <section class="panel audit-filter-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Narrow the roster</p>
                    <h2>Filters</h2>
                </div>
                @if ($appliedFilters !== [])
                    <a class="btn btn-light audit-clear-filters" href="{{ route('settings.biometric-terminals.index', ['device' => $device->id]) }}">
                        <x-icon name="close" />
                        Clear all filters
                    </a>
                @endif
            </div>

            <form method="GET" action="{{ route('settings.biometric-terminals.index') }}" class="audit-filters" role="search" aria-label="Filter the enrolment roster">
                <input type="hidden" name="device" value="{{ $device->id }}">
                <label>
                    <span>Search</span>
                    <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name or record number">
                </label>
                <label>
                    <span>Department</span>
                    <select name="department_id">
                        <option value="">All departments</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Enrolment state</span>
                    <select name="state">
                        <option value="">Any state</option>
                        <option value="unassigned" @selected(($filters['state'] ?? '') === 'unassigned')>No PIN yet</option>
                        <option value="assigned" @selected(($filters['state'] ?? '') === 'assigned')>Awaiting capture</option>
                        <option value="captured" @selected(($filters['state'] ?? '') === 'captured')>Fingerprint captured</option>
                        <option value="retired" @selected(($filters['state'] ?? '') === 'retired')>Retired</option>
                    </select>
                </label>
                <label>
                    <span>Employment</span>
                    <select name="employment">
                        <option value="active" @selected(($filters['employment'] ?? 'active') === 'active')>Current staff</option>
                        <option value="inactive" @selected(($filters['employment'] ?? '') === 'inactive')>Departed or archived</option>
                    </select>
                </label>
                <div class="audit-filter-actions">
                    <span aria-hidden="true">&nbsp;</span>
                    <div>
                        <button class="btn btn-primary" type="submit">Apply filters</button>
                        @if ($appliedFilters !== [])
                            <a class="btn btn-light audit-clear-filters" href="{{ route('settings.biometric-terminals.index', ['device' => $device->id]) }}">Clear</a>
                        @endif
                    </div>
                </div>
            </form>

            @if ($activeFilters !== [])
                <div class="audit-active-filters">
                    <span class="audit-active-filters-label">Showing staff where</span>
                    <ul>
                        @foreach ($activeFilters as $active)
                            <li>
                                <span><strong>{{ $active['label'] }}:</strong> {{ $active['value'] }}</span>
                                <a
                                    href="{{ route('settings.biometric-terminals.index', $active['query'] + ['device' => $device->id]) }}"
                                    aria-label="Remove the {{ strtolower($active['label']) }} filter"
                                >
                                    <x-icon name="close" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section class="panel workforce-table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">{{ $device->name }}</p>
                    <h2>Enrolment roster</h2>
                </div>
                <div class="bio-row-actions">
                    <span class="history-caption" role="status">
                        @if ($roster->total() > 0)
                            Showing {{ number_format($roster->firstItem()) }}&ndash;{{ number_format($roster->lastItem()) }}
                            of {{ number_format($roster->total()) }} staff
                        @else
                            No staff to show
                        @endif
                    </span>
                    @if ($summary['unassigned'] > 0)
                        <form
                            method="POST"
                            action="{{ route('settings.biometric-enrollments.bulk') }}"
                            data-confirm="Reserve a PIN for all {{ number_format($summary['people']) }} active staff on {{ $device->name }}? This writes a row for every active employee. Fingerprints still have to be captured at the terminal afterwards, and nobody is shown as enrolled until that happens."
                            data-confirm-button="Reserve the PINs"
                            data-confirm-tone="caution"
                        >
                            @csrf
                            <input type="hidden" name="biometric_device_id" value="{{ $device->id }}">
                            <input type="hidden" name="confirm" value="1">
                            <button class="btn btn-primary btn-sm" type="submit">
                                Reserve PINs for all active staff
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="table-responsive">
                <table class="dashboard-table workforce-table table-stack">
                    <caption class="visually-hidden">
                        Who is enrolled on this terminal. Each row gives the employee, the device PIN
                        reserved for them, whether their fingerprint has actually been captured, and the
                        actions available.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Employee</th>
                            <th scope="col">Department</th>
                            <th scope="col">Device PIN</th>
                            <th scope="col">State</th>
                            <th scope="col">Captured</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roster as $employee)
                            @php $enrollment = $employee->biometricEnrollments->first(); @endphp
                            <tr>
                                <td data-label="Employee">
                                    <strong>{{ $employee->full_name }}</strong>
                                    <small class="d-block text-muted">{{ $employee->employee_number }}</small>
                                </td>
                                <td data-label="Department">
                                    {{ $employee->department?->name ?? '—' }}
                                    <small class="d-block text-muted">{{ $employee->position?->title }}</small>
                                </td>
                                <td data-label="Device PIN"><code>{{ $enrollment?->external_user_id ?? '—' }}</code></td>
                                <td data-label="State">
                                    @if ($enrollment === null)
                                        <span class="bio-pill">No PIN yet</span>
                                    @elseif (! $enrollment->is_active)
                                        <span class="bio-pill is-retired">Retired</span>
                                    @elseif ($enrollment->enrolled_at === null)
                                        <span class="bio-pill is-pending">Awaiting capture</span>
                                    @else
                                        <span class="bio-pill is-captured">Captured</span>
                                    @endif
                                </td>
                                <td data-label="Captured">{{ $enrollment?->enrolled_at?->format('j M Y') ?? '—' }}</td>
                                <td data-label="Actions">
                                    <div class="bio-row-actions">
                                        @if ($enrollment === null || ! $enrollment->is_active)
                                            <form method="POST" action="{{ route('settings.biometric-enrollments.store') }}">
                                                @csrf
                                                <input type="hidden" name="biometric_device_id" value="{{ $device->id }}">
                                                <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                                <button class="btn btn-light btn-sm" type="submit">Reserve PIN</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('settings.biometric-enrollments.update', $enrollment) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="action" value="{{ $enrollment->enrolled_at === null ? 'capture' : 'release' }}">
                                                <button class="btn btn-light btn-sm" type="submit">
                                                    {{ $enrollment->enrolled_at === null ? 'Mark captured' : 'Needs recapture' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('settings.biometric-enrollments.update', $enrollment) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="action" value="deactivate">
                                                <button class="btn btn-light btn-sm" type="submit">Retire</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="empty-table-cell" colspan="6">
                                    @if ($appliedFilters !== [])
                                        No staff match these filters. Clear them to see the whole roster.
                                    @else
                                        There are no active employees to enrol.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($roster->hasPages())
                <div class="report-pagination">{{ $roster->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            @endif
        </section>
    @endif
@endsection
