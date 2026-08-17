@extends('layouts.app')

@section('title', 'Shift Templates')

@section('content')
    <section class="page-heading schedule-heading">
        <div>
            <p class="eyebrow">Shift & Schedule Management</p>
            <h1>Shift templates</h1>
            <p>Maintain the standard working hours used when building employee schedules.</p>
        </div>
        <div class="schedule-heading-actions">
            <a class="btn btn-outline-primary dashboard-action" href="{{ route('schedules.index') }}"><x-icon name="calendar" /> Schedule calendar</a>
            @if($canManageData)<button class="btn btn-primary dashboard-action" type="button" data-bs-toggle="modal" data-bs-target="#shiftTemplateModal" data-new-shift><x-icon name="plus" /> New shift</button>@endif
        </div>
    </section>

    @if (session('success'))
        <div class="attendance-alert attendance-alert-success" role="status"><x-icon name="check-circle" /><span>{{ session('success') }}</span></div>
    @endif

    @if ($errors->any())
        <div class="attendance-alert attendance-alert-danger" role="alert"><x-icon name="close" /><span>{{ $errors->first() }}</span></div>
    @endif

    <section class="shift-summary-grid">
        <article class="report-stat"><span class="report-stat-icon report-stat-blue"><x-icon name="clock" /></span><div><span>Total templates</span><strong>{{ $shifts->count() }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-green"><x-icon name="check-circle" /></span><div><span>Active templates</span><strong>{{ $shifts->where('is_active', true)->count() }}</strong></div></article>
        <article class="report-stat"><span class="report-stat-icon report-stat-violet"><x-icon name="repeat" /></span><div><span>Overnight shifts</span><strong>{{ $shifts->filter->crosses_midnight->count() }}</strong></div></article>
    </section>

    <section class="panel shift-template-panel">
        <div class="panel-header">
            <div><p class="panel-kicker">Reusable work patterns</p><h2>Available shift templates</h2></div>
            <span class="history-caption">{{ $shifts->sum('assignments_count') }} schedule assignments</span>
        </div>

        <p class="shift-template-hint">Every template can be edited or deactivated. Templates already used in a schedule can't be deleted &mdash; deactivate them instead to hide them from future assignments while keeping existing schedule history intact. System templates are always editable but can never be deleted.</p>

        <div class="shift-template-table-wrap">
            <table class="shift-template-table">
                <thead><tr><th>Shift</th><th>Working hours</th><th>Paid duration</th><th>Usage</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($shifts as $shift)
                        @php
                            $shiftPayload = ['id' => $shift->id, 'code' => $shift->code, 'name' => $shift->name, 'start_time' => substr($shift->start_time, 0, 5), 'end_time' => substr($shift->end_time, 0, 5), 'break_minutes' => $shift->break_minutes, 'color' => $shift->color, 'is_active' => $shift->is_active, 'is_rotating' => $shift->is_rotating];
                        @endphp
                        <tr>
                            <td>
                                <div class="shift-identity">
                                    <span class="shift-color" style="background: {{ $shift->color }}"></span>
                                    <div class="shift-identity-body">
                                        <div class="shift-identity-title">
                                            <strong>{{ $shift->name }}</strong>
                                            @if($shift->is_system)
                                                <x-status-badge status="System" />
                                            @endif
                                        </div>
                                        <span class="shift-code-chip">{{ $shift->code }}</span>
                                    </div>
                                </div>
                            </td>
                            <td><div class="shift-hours"><strong>{{ $shift->formatted_time }}</strong><small>{{ $shift->break_minutes }}-minute break @if($shift->crosses_midnight) · Overnight @endif</small></div></td>
                            <td>{{ number_format($shift->duration_minutes / 60, 1) }} hours</td>
                            <td>{{ number_format($shift->assignments_count) }} assignments</td>
                            <td>
                                <span @class(['shift-status', 'active' => $shift->is_active, 'inactive' => ! $shift->is_active])>
                                    <x-icon :name="$shift->is_active ? 'check-circle' : 'circle'" />
                                    {{ $shift->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td>
                                <div class="shift-row-actions">
                                    @if($canManageData)
                                    <button
                                        class="icon-button subtle"
                                        type="button"
                                        aria-label="Edit {{ $shift->name }}"
                                        title="Edit"
                                        data-edit-shift
                                        data-shift='{{ json_encode($shiftPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}'
                                        data-bs-toggle="modal"
                                        data-bs-target="#shiftTemplateModal"
                                    ><x-icon name="edit" /></button>
                                    <form
                                        method="POST"
                                        action="{{ route('shifts.toggle-active', $shift) }}"
                                        @if ($shift->is_active) onsubmit="return confirm('Deactivate this shift template? It will be hidden from new schedule assignments, but existing schedules keep this shift.')" @endif
                                    >
                                        @csrf @method('PATCH')
                                        <button
                                            class="shift-toggle-btn {{ $shift->is_active ? 'shift-toggle-btn-deactivate' : 'shift-toggle-btn-activate' }}"
                                            type="submit"
                                            aria-label="{{ $shift->is_active ? 'Deactivate' : 'Activate' }} {{ $shift->name }}"
                                            title="{{ $shift->is_active ? 'Hide from future assignments' : 'Make available for assignments' }}"
                                        >
                                            <x-icon :name="$shift->is_active ? 'circle' : 'check-circle'" />
                                            {{ $shift->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                    @if (! $shift->is_system && $shift->assignments_count === 0)
                                        <form method="POST" action="{{ route('shifts.destroy', $shift) }}" onsubmit="return confirm('Delete this unused shift template?')">@csrf @method('DELETE')<button class="icon-button subtle text-danger" type="submit" aria-label="Delete {{ $shift->name }}" title="Delete"><x-icon name="trash" /></button></form>
                                    @endif
                                    @else
                                        <span class="shift-template-hint">View only</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="calendar-empty-state"><x-icon name="clock" /><strong>No shift templates yet</strong><span>Create a template to begin scheduling employees.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($canManageData)
    <div class="modal fade" id="shiftTemplateModal" tabindex="-1" aria-labelledby="shiftTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content schedule-modal-content">
            <form method="POST" action="{{ route('shifts.store') }}" id="shiftTemplateForm" data-store-url="{{ route('shifts.store') }}" data-update-url-template="{{ route('shifts.update', ['shift' => '__ID__']) }}">
                @csrf
                <input type="hidden" name="_method" value="POST" data-method-field>
                <div class="modal-header"><div><p class="panel-kicker">Reusable work pattern</p><h2 class="modal-title" id="shiftTemplateModalLabel">New shift template</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body schedule-form-grid">
                    <label class="shift-code-field"><span>Shift code</span><input type="text" data-shift-code-preview value="" placeholder="Generated after saving" readonly aria-label="Shift code, generated automatically after saving"></label>
                    <label><span>Shift name</span><input type="text" name="name" maxlength="100" placeholder="Day Shift" required></label>
                    <label><span>Start time</span><input type="time" name="start_time" required></label>
                    <label><span>End time</span><input type="time" name="end_time" required></label>
                    <label><span>Break (minutes)</span><input type="number" name="break_minutes" min="0" max="480" value="60" required></label>
                    <fieldset class="shift-color-choices"><legend>Calendar color</legend><div>
                        @foreach (['#176B43' => 'Green', '#2F80ED' => 'Blue', '#8B5CF6' => 'Purple', '#334155' => 'Slate', '#D97706' => 'Orange', '#DC2626' => 'Red'] as $color => $label)
                            <label title="{{ $label }}"><input type="radio" name="color" value="{{ $color }}" @checked($color === '#176B43')><span class="shift-color-choice" style="background: {{ $color }}"><span class="visually-hidden">{{ $label }}</span></span></label>
                        @endforeach
                    </div></fieldset>
                    <label class="shift-active-toggle full-width"><input type="checkbox" name="is_rotating" value="1" checked><span><strong>Part of a shift rotation</strong><small>On for a shift that covers one part of the day and needs others to complete it, like Morning, Afternoon and Night. Off for a standalone office day such as 8:00 AM&ndash;5:00 PM, which the assistant can then roster on its own and without night-shift rules.</small></span></label>
                    <label class="shift-active-toggle full-width"><input type="checkbox" name="is_active" value="1" checked><span><strong>Active template</strong><small>Available for new assignments and recurring schedules.</small></span></label>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save template</button></div>
            </form>
        </div></div>
    </div>
    @endif
@endsection
