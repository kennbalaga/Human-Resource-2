@extends('layouts.app')

@section('title', 'Payslip')

@use('App\Support\Duration')

@section('content')
    @php
        $employee = $payslip['employee'];
        $period = $payslip['period'];
        $days = $payslip['days'];
        $minutes = $payslip['minutes'];
    @endphp

    {{-- The wrapper holds the document's column so the heading above the sheet
         shares its width: the Download button lines up with the sheet's right
         edge instead of floating out in the content area beside it. --}}
    <div class="payslip-page">
        {{-- The eyebrow is a section label everywhere else in the app, so the way
             back is a real control in the action row rather than a 12px uppercase
             link hidden in that label -- the same shape attendance/override uses.
             Secondary sits left of the primary action. --}}
        <section class="page-heading workforce-heading">
            <div>
                <p class="eyebrow">Time &amp; attendance · Payslips</p>
                <h1>Payslip · {{ $period->label() }}</h1>
            </div>
            <div class="payslip-heading-actions">
                <a class="btn btn-outline-primary dashboard-action" href="{{ route('payslips.index') }}"><x-icon name="arrow-left" /> Back to payslips</a>
                <a class="btn btn-primary dashboard-action" data-download href="{{ route('payslips.download', [$employee, $period->key()]) }}"><x-icon name="download" /> Download PDF</a>
            </div>
        </section>

        <article class="panel payslip-sheet">
            {{-- The masthead answers who the document is about; "Payslip" is the
                 page's h1 and is not repeated here. --}}
            <header class="payslip-header">
                <div class="payslip-brand">
                    <span class="payslip-logo"><x-brand-mark :size="48" /></span>
                    <div>
                        <p class="panel-kicker">{{ config('branding.organization') }}</p>
                        <h2>{{ $employee->full_name }}</h2>
                        <p class="payslip-subject-meta"><span class="employee-number">{{ $payslip['number'] }}</span></p>
                    </div>
                </div>
                {{-- Written out rather than <x-status-badge>: that component maps a
                     status string through a match these two wordings are not in,
                     and headline-cases what it is given, which would print
                     "Sent To Payroll". --}}
                <div class="payslip-flags">
                    @if($payslip['complete'])
                        <span class="status-badge status-primary"><span class="status-dot"></span>Sent to payroll</span>
                    @else
                        <span class="status-badge status-warning"><span class="status-dot"></span>Partial</span>
                    @endif
                </div>
            </header>

            @unless($payslip['complete'])
                <div class="attendance-alert attendance-alert-warning payslip-partial">
                    <x-icon name="alert" />
                    <span>{{ $payslip['weeks']['pending'] }} {{ str('timesheet')->plural($payslip['weeks']['pending']) }} in this period {{ $payslip['weeks']['pending'] === 1 ? 'is' : 'are' }} not approved yet. {{ $days['pending'] }} {{ str('day')->plural($days['pending']) }} will be added once {{ $payslip['weeks']['pending'] === 1 ? 'it is' : 'they are' }} approved.</span>
                </div>
            @endunless

            <dl class="payslip-meta">
                <div><dt>Employee ID</dt><dd>{{ $employee->employee_number }}</dd></div>
                <div><dt>Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd></div>
                <div><dt>Position</dt><dd>{{ $employee->position?->title ?? '—' }}</dd></div>
                <div><dt>Pay period</dt><dd>{{ $period->start->format('M j') }} – {{ $period->end->format('M j, Y') }}</dd></div>
                {{-- One expression rather than a trailing @if: Blade only reads a
                     directive whose @ does not follow a word character, so an
                     @if opened straight after a word renders as literal text. --}}
                <div><dt>Timesheets</dt><dd>{{ $payslip['weeks']['approved'] }} approved{{ $payslip['weeks']['pending'] ? ' · '.$payslip['weeks']['pending'].' pending' : '' }}</dd></div>
                <div><dt>Last approved</dt><dd>{{ $payslip['approved_at']?->format('M j, Y') ?? '—' }}@if($payslip['approved_by'])<small>by {{ $payslip['approved_by'] }}</small>@endif</dd></div>
            </dl>

            <div class="payslip-columns">
                <section class="payslip-section">
                    <h3>Days</h3>
                    <table class="payslip-table payslip-numeric">
                        <tbody>
                            <tr><th scope="row">Scheduled</th><td>{{ $days['scheduled'] }}</td></tr>
                            <tr><th scope="row">Worked</th><td>{{ $days['worked'] }}</td></tr>
                            <tr><th scope="row">On leave</th><td>{{ Duration::days($days['leave']) }}</td></tr>
                            @if($days['pending'])
                                <tr><th scope="row">Awaiting approval</th><td>{{ $days['pending'] }}</td></tr>
                            @endif
                            <tr><th scope="row">Absent</th><td>{{ $days['absent'] }}</td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="payslip-section">
                    <h3>Hours</h3>
                    <table class="payslip-table payslip-numeric">
                        <tbody>
                            <tr><th scope="row">Regular</th><td>{{ Duration::hm($minutes['regular']) }}</td></tr>
                            <tr><th scope="row">Overtime</th><td>{{ Duration::hm($minutes['overtime']) }}</td></tr>
                            <tr><th scope="row">Late</th><td>{{ $minutes['late'] }} min</td></tr>
                            <tr><th scope="row">Undertime</th><td>{{ $minutes['undertime'] }} min</td></tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <div class="payslip-columns">
                <section class="payslip-section">
                    <h3>Leave</h3>
                    @if(empty($payslip['leave']))
                        <p class="payslip-empty">No approved leave in this period.</p>
                    @else
                        <table class="payslip-table">
                            <tbody>
                                @foreach($payslip['leave'] as $leave)
                                    <tr>
                                        <th scope="row">{{ $leave['type'] }}<small>{{ $leave['category'] }} · {{ $leave['from']->format('M j') }}@unless($leave['from']->isSameDay($leave['to']))–{{ $leave['to']->format('M j') }}@endunless</small></th>
                                        <td>{{ Duration::days($leave['days']) }} {{ str('day')->plural($leave['days']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>

                <section class="payslip-section">
                    <h3>Holidays</h3>
                    @if($payslip['holidays']->isEmpty())
                        <p class="payslip-empty">No holidays in this period.</p>
                    @else
                        <table class="payslip-table">
                            <tbody>
                                @foreach($payslip['holidays'] as $holiday)
                                    <tr><th scope="row">{{ $holiday->name }}<small>{{ $holiday->typeLabel() }}</small></th><td>{{ $holiday->date->format('D, M j') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>

            {{-- The line items stay, so the document still reads as a payslip, but
                 the dash is the placeholder and the note below is the one place
                 the reason is written -- not once per row. --}}
            <div class="payslip-amounts">
                <div class="payslip-columns">
                    <section class="payslip-section">
                        <h3>Earnings</h3>
                        <table class="payslip-table payslip-numeric">
                            <tbody>
                                @foreach($payslip['earnings'] as $label)
                                    <tr><th scope="row">{{ $label }}</th><td class="payslip-placeholder">—</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>

                    <section class="payslip-section">
                        <h3>Deductions</h3>
                        <table class="payslip-table payslip-numeric">
                            <tbody>
                                @foreach($payslip['deductions'] as $label)
                                    <tr><th scope="row">{{ $label }}</th><td class="payslip-placeholder">—</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                </div>
                <p class="payslip-note">Computed by Payroll. Amounts are released with the payout and are not held in this system — a dash means no figure yet, not zero.</p>
            </div>

            <div class="payslip-net">
                <span>Net pay</span>
                <strong class="payslip-placeholder">—</strong>
            </div>

            <section class="payslip-section">
                <h3>Daily record</h3>
                {{-- Not .table-responsive: that wrapper scrolls on both axes, so
                     it hangs a scrollbar down a table that fits. This one only
                     becomes a scroller at the width that needs one. --}}
                <div class="payslip-daily-scroll">
                    <table class="payslip-table payslip-daily payslip-numeric">
                        <caption class="visually-hidden">Approved attendance for each day of {{ $period->label() }}</caption>
                        <thead>
                            <tr><th scope="col">Date</th><th scope="col">Regular</th><th scope="col">Overtime</th><th scope="col">Late</th><th scope="col">Undertime</th></tr>
                        </thead>
                        <tbody>
                            @foreach($payslip['entries'] as $entry)
                                <tr>
                                    <th scope="row">{{ $entry->work_date->format('D, M j') }}</th>
                                    <td>{{ Duration::hm($entry->regular_minutes) }}</td>
                                    <td>{{ $entry->overtime_minutes ? Duration::hm($entry->overtime_minutes) : '—' }}</td>
                                    <td>{{ $entry->late_minutes ? $entry->late_minutes.'m' : '—' }}</td>
                                    <td>{{ $entry->undertime_minutes ? $entry->undertime_minutes.'m' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="payslip-footnote">Confidential. This payslip shows the approved attendance payroll pays on. Earnings, deductions and net pay are computed by Payroll, outside this system.</p>
        </article>
    </div>
@endsection
