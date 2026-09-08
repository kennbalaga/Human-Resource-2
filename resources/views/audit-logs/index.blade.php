@extends('layouts.app')

@section('title', 'Audit Logs')

@php
    use App\Support\AuditActivity;

    /*
     * The filters that survived validation, stripped of blanks. Used for the
     * export links and the "clear" affordance so both agree with the table.
     * request()->query() is deliberately not reused: it carries `page`, and an
     * export of "page 4" is not a thing.
     */
    $appliedFilters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
@endphp

@section('content')
    <section class="page-heading audit-heading">
        <div>
            <div class="audit-heading-meta">
                <p class="eyebrow">Security &amp; Compliance</p>
                <span class="audit-integrity-chip">
                    <x-icon name="lock" />
                    Read-only &middot; append-only
                </span>
            </div>
            <h1>Audit Logs</h1>
            <p>
                Who changed what, from where, and whether the system allowed it — sign&#8209;ins, employee
                records, schedules, leave and timesheets.
            </p>
        </div>
        <div class="audit-heading-actions">
            <div class="dropdown">
                <button
                    class="btn btn-primary dashboard-action audit-export-toggle"
                    type="button"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="true"
                    aria-expanded="false"
                >
                    <x-icon name="download" />
                    Export CSV
                    <x-icon name="chevron-down" class="audit-export-caret" />
                </button>
                <ul class="dropdown-menu dropdown-menu-end audit-export-menu">
                    <li>
                        <a
                            class="dropdown-item"
                            data-audit-export
                            href="{{ route('audit-logs.export', $appliedFilters + ['scope' => 'filtered']) }}"
                        >
                            <x-icon name="report" />
                            <span>
                                <strong>Export these results</strong>
                                <small>{{ number_format($summary['total']) }} {{ Str::plural('event', $summary['total']) }} matching the filters below</small>
                            </span>
                        </a>
                    </li>
                    <li>
                        <a
                            class="dropdown-item"
                            data-audit-export
                            href="{{ route('audit-logs.export', ['scope' => 'all']) }}"
                        >
                            <x-icon name="shield" />
                            <span>
                                <strong>Export the full trail</strong>
                                <small>Every retained event, ignoring the filters</small>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    {{-- Export feedback. A streamed download gives the page no completion event
         of its own, so audit-logs.js drives this strip from the cookie the
         export response sets. --}}
    <p class="audit-export-status" data-audit-export-status role="status" aria-live="polite" hidden></p>

    <section class="audit-summary-grid">
        <article class="audit-summary-card">
            <span class="audit-summary-icon"><x-icon name="report" /></span>
            <p class="audit-summary-label">Recorded events</p>
            <strong class="audit-summary-value">{{ number_format($summary['total']) }}</strong>
            <small>{{ $appliedFilters === [] ? 'Across the full retained trail' : 'Matching the current filters' }}</small>
        </article>
        <article @class(['audit-summary-card', 'is-attention' => $summary['attention'] > 0])>
            <span class="audit-summary-icon danger"><x-icon name="alert" /></span>
            <p class="audit-summary-label">Refused or failed</p>
            <strong class="audit-summary-value">{{ number_format($summary['attention']) }}</strong>
            <small>Failed sign-ins, blocked actions and errors</small>
        </article>
        <article class="audit-summary-card">
            <span class="audit-summary-icon info"><x-icon name="edit" /></span>
            <p class="audit-summary-label">Record changes</p>
            <strong class="audit-summary-value">{{ number_format($summary['changes']) }}</strong>
            <small>Updates and deletions, excluding new entries</small>
        </article>
        <article class="audit-summary-card">
            <span class="audit-summary-icon"><x-icon name="users" /></span>
            <p class="audit-summary-label">Staff involved</p>
            <strong class="audit-summary-value">{{ number_format($summary['actors']) }}</strong>
            <small>Distinct signed-in accounts</small>
        </article>
    </section>

    <section class="panel audit-filter-panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">Narrow the trail</p>
                <h2>Filters</h2>
            </div>
            @if ($appliedFilters !== [])
                <a class="btn btn-light audit-clear-filters" href="{{ route('audit-logs.index') }}">
                    <x-icon name="close" />
                    Clear all filters
                </a>
            @endif
        </div>

        <form method="GET" action="{{ route('audit-logs.index') }}" class="audit-filters" role="search" aria-label="Filter audit events">
            {{-- audit-logs.js upgrades this into a searchable combobox. The
                 select stays in the form and stays the thing that submits, so
                 the filter is still an exact user_id and the field still works
                 with scripting unavailable. --}}
            <label data-user-picker>
                <span id="auditUserLabel">User</span>
                <select name="user_id" data-user-picker-select>
                    <option value="">All users</option>
                    {{-- Closed accounts stay selectable — they are often the
                         point of the search — but are marked, so nobody reads a
                         departed colleague as still working here. --}}
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') == $user->id)>{{ $user->name }}@unless ($user->is_active) · Inactive @endunless</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Activity type</span>
                <select name="activity">
                    <option value="">Any activity</option>
                    @foreach (AuditActivity::ACTIVITIES as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['activity'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Module</span>
                <select name="module">
                    <option value="">All modules</option>
                    @foreach (AuditActivity::MODULES as $key => $module)
                        <option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $module['label'] }}</option>
                    @endforeach
                    <option value="other" @selected(($filters['module'] ?? '') === 'other')>Other</option>
                </select>
            </label>
            <label>
                <span>Outcome</span>
                <select name="status">
                    <option value="">Any outcome</option>
                    @foreach (AuditActivity::STATUS_GROUPS as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Action contains</span>
                <input name="action" value="{{ $filters['action'] ?? '' }}" placeholder="e.g. leaves.approve" aria-describedby="auditActionHint">
                <small id="auditActionHint">Matches part of the recorded action name.</small>
            </label>
            <label>
                <span>From date</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </label>
            <label>
                <span>To date</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </label>
            {{-- The empty span mirrors a filter's own label row, so the buttons
                 line up with the inputs beside them rather than with the labels
                 above them. --}}
            <div class="audit-filter-actions">
                <span aria-hidden="true">&nbsp;</span>
                <div>
                    <button class="btn btn-primary" type="submit">Apply filters</button>
                    @if ($appliedFilters !== [])
                        <a class="btn btn-light audit-clear-filters" href="{{ route('audit-logs.index') }}">Clear</a>
                    @endif
                </div>
            </div>
        </form>

        @if ($activeFilters !== [])
            <div class="audit-active-filters">
                <span class="audit-active-filters-label">Showing events where</span>
                <ul>
                    @foreach ($activeFilters as $active)
                        <li>
                            <span><strong>{{ $active['label'] }}:</strong> {{ $active['value'] }}</span>
                            <a
                                href="{{ route('audit-logs.index', $active['query']) }}"
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

    <section class="panel workforce-table-panel audit-table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">Immutable activity trail</p>
                <h2>Recorded write requests</h2>
            </div>
            <span class="history-caption" role="status">
                @if ($logs->total() > 0)
                    Showing {{ number_format($logs->firstItem()) }}–{{ number_format($logs->lastItem()) }}
                    of {{ number_format($logs->total()) }} events
                @else
                    No events to show
                @endif
            </span>
        </div>

        <div class="table-responsive">
            <table class="dashboard-table workforce-table audit-table table-stack">
                <caption class="visually-hidden">
                    Audit trail of write requests. Each row records the time, the account responsible, the
                    action, the module it belongs to, the request that was made, the record it affected and
                    the outcome. Open an event to see its originating IP address and full detail.
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Date &amp; time</th>
                        <th scope="col">User</th>
                        <th scope="col">Action</th>
                        <th scope="col">Module</th>
                        <th scope="col">Request</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Outcome</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        @php
                            $module = AuditActivity::module($log);
                            $activity = AuditActivity::activity($log);
                            $status = AuditActivity::status($log->response_status);
                            $subject = $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null;
                            $endpoint = '/'.ltrim((string) $log->path, '/');
                        @endphp
                        <tr @class(['audit-row', 'needs-attention' => AuditActivity::needsAttention($log)])>
                            <td data-label="Date & time">
                                <time class="audit-timestamp" datetime="{{ $log->created_at->toIso8601String() }}">
                                    <strong>{{ $log->created_at->format('M j, Y') }}</strong>
                                    <span>{{ $log->created_at->format('g:i:s A') }}</span>
                                </time>
                            </td>
                            <td data-label="User">
                                @if ($log->user)
                                    <span class="audit-user">
                                        <strong>{{ $log->user->name }}</strong>
                                        <small>{{ $log->user->roles->first()?->name ?? 'No role assigned' }}</small>
                                    </span>
                                @else
                                    <span class="audit-user is-anonymous">
                                        <strong>Unauthenticated</strong>
                                        <small>No signed-in account</small>
                                    </span>
                                @endif
                            </td>
                            <td data-label="Action">
                                <span class="audit-action">
                                    <strong>{{ AuditActivity::label($log) }}</strong>
                                    <small class="audit-activity-tag">{{ $activity['label'] }}</small>
                                </span>
                            </td>
                            <td data-label="Module">
                                <span class="audit-module-chip">{{ $module['label'] }}</span>
                            </td>
                            <td data-label="Request">
                                <span class="audit-request">
                                    <span class="audit-method audit-method-{{ strtolower($log->method) }}">{{ $log->method }}</span>
                                    <code title="{{ $endpoint }}">{{ $endpoint }}</code>
                                </span>
                            </td>
                            <td data-label="Subject">
                                @if ($subject)
                                    <span class="audit-subject">{{ $subject }}</span>
                                @else
                                    <span class="audit-empty" aria-label="No specific record">—</span>
                                @endif
                            </td>
                            <td data-label="Outcome">
                                <span class="audit-status audit-status-{{ $status['tone'] }}">
                                    <span class="audit-status-dot" aria-hidden="true"></span>
                                    {{ $log->response_status ?? '—' }}
                                    <span class="audit-status-word">{{ $status['label'] }}</span>
                                </span>
                            </td>
                            <td>
                                <div class="organization-row-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-light"
                                    data-audit-detail
                                    data-bs-toggle="modal"
                                    data-bs-target="#auditDetailModal"
                                    data-event-id="{{ $log->id }}"
                                    data-timestamp="{{ $log->created_at->format('l, F j, Y \a\t g:i:s A') }}"
                                    data-timestamp-iso="{{ $log->created_at->toIso8601String() }}"
                                    data-user="{{ $log->user?->name ?? 'Unauthenticated' }}"
                                    data-user-role="{{ $log->user?->roles->first()?->name ?? 'No signed-in account' }}"
                                    data-action="{{ AuditActivity::label($log) }}"
                                    data-action-raw="{{ $log->action }}"
                                    data-route="{{ $log->route_name ?? 'Unnamed route' }}"
                                    data-module="{{ $module['label'] }}"
                                    data-activity="{{ $activity['label'] }}"
                                    data-method="{{ $log->method }}"
                                    data-endpoint="{{ $endpoint }}"
                                    data-subject="{{ $subject ?? 'No specific record' }}"
                                    data-ip="{{ $log->ip_address ?? 'Not recorded' }}"
                                    data-status="{{ $log->response_status ?? '—' }}"
                                    data-status-label="{{ $status['label'] }}"
                                    data-status-tone="{{ $status['tone'] }}"
                                    data-device="{{ $log->user_agent ?? 'Not recorded' }}"
                                    data-request-id="{{ $log->metadata['request_id'] ?? 'Not recorded' }}"
                                    data-fields="{{ implode(', ', $log->metadata['input_fields'] ?? []) }}"
                                    aria-label="View full details of event {{ $log->id }}"
                                >View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty-table-cell">
                                <x-icon name="shield" />
                                @if ($appliedFilters !== [])
                                    <strong>No events match these filters</strong>
                                    <span>Try widening the date range or clearing a filter above.</span>
                                @else
                                    <strong>No audit records yet</strong>
                                    <span>Write actions will be recorded here automatically.</span>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="report-pagination">{{ $logs->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif
    </section>

    @include('audit-logs._detail-modal')
@endsection
