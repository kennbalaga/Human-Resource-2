@extends('layouts.app')

@section('title', 'Payslip')

@section('content')
    @php
        $formatMinutes = fn (int $minutes) => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
        $formatDays = fn (float $days) => rtrim(rtrim(number_format($days, 1), '0'), '.');
        $employee = $payslip['employee'];
        $period = $payslip['period'];
    @endphp

    <section class="page-heading workforce-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('payslips.index') }}">Payslips</a></p>
            <h1>Payslip · {{ $period->label() }}</h1>
            <p>{{ $employee->full_name }} · {{ $payslip['number'] }}</p>
        </div>
        <a class="btn btn-primary dashboard-action" href="{{ route('payslips.download', [$employee, $period->key()]) }}"><x-icon name="download" /> Download PDF</a>
    </section>

    <article class="panel payslip-sheet">
        <header class="payslip-header">
            <div class="payslip-brand">
                <span class="payslip-logo"><x-brand-mark :size="52" /></span>
                <div>
                    <p class="panel-kicker">{{ config('branding.organization') }}</p>
                    <h2>Payslip</h2>
                </div>
            </div>
            <div class="payslip-flags">
                <span class="payslip-confidential">Confidential</span>
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
                <span>{{ $payslip['weeks']['pending'] }} {{ str('timesheet')->plural($payslip['weeks']['pending']) }} in this period {{ $payslip['weeks']['pending'] === 1 ? 'is' : 'are' }} not approved yet. {{ $payslip['days']['pending'] }} {{ str('day')->plural($payslip['days']['pending']) }} will be added once {{ $payslip['weeks']['pending'] === 1 ? 'it is' : 'they are' }} approved.</span>
            </div>
        @endunless

        <dl class="payslip-meta">
            <div><dt>Employee</dt><dd>{{ $employee->full_name }}</dd></div>
            <div><dt>Employee ID</dt><dd>{{ $employee->employee_number }}</dd></div>
            <div><dt>Payslip no.</dt><dd>{{ $payslip['number'] }}</dd></div>
            <div><dt>Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd></div>
            <div><dt>Position</dt><dd>{{ $employee->position?->title ?? '—' }}</dd></div>
            <div><dt>Pay period</dt><dd>{{ $period->start->format('M j, Y') }} – {{ $period->end->format('M j, Y') }}</dd></div>
            <div><dt>Last approved</dt><dd>{{ $payslip['approved_at']?->format('M j, Y') ?? '—' }}@if($payslip['approved_by']) by {{ $payslip['approved_by'] }}@endif</dd></div>
        </dl>

        <div class="payslip-columns">
            <section class="payslip-section">
                <h3>Attendance</h3>
                <table class="payslip-table">
                    <tbody>
                        <tr><th>Days scheduled</th><td>{{ $payslip['days']['scheduled'] }}</td></tr>
                        <tr><th>Days worked</th><td>{{ $payslip['days']['worked'] }}</td></tr>
                        <tr><th>Days on leave</th><td>{{ $formatDays($payslip['days']['leave']) }}</td></tr>
                        @if($payslip['days']['pending'])
                            <tr><th>Awaiting approval</th><td>{{ $payslip['days']['pending'] }}</td></tr>
                        @endif
                        <tr><th>Absences</th><td>{{ $payslip['days']['absent'] }}</td></tr>
                        <tr><th>Regular hours</th><td>{{ $formatMinutes($payslip['minutes']['regular']) }}</td></tr>
                        <tr><th>Overtime</th><td>{{ $formatMinutes($payslip['minutes']['overtime']) }}</td></tr>
                        <tr><th>Late</th><td>{{ $payslip['minutes']['late'] }} min</td></tr>
                        <tr><th>Undertime</th><td>{{ $payslip['minutes']['undertime'] }} min</td></tr>
                    </tbody>
                </table>
            </section>

            <div class="payslip-stack">
                <section class="payslip-section">
                    <h3>Leave</h3>
                    @if(empty($payslip['leave']))
                        <p class="payslip-empty">No approved leave in this period.</p>
                    @else
                        <table class="payslip-table">
                            <tbody>
                                @foreach($payslip['leave'] as $leave)
                                    <tr>
                                        <th>{{ $leave['type'] }}<small>{{ $leave['category'] }} · {{ $leave['from']->format('M j') }}@unless($leave['from']->isSameDay($leave['to']))–{{ $leave['to']->format('M j') }}@endunless</small></th>
                                        <td>{{ $formatDays($leave['days']) }} {{ str('day')->plural($leave['days']) }}</td>
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
                                    <tr><th>{{ $holiday->name }}<small>{{ $holiday->typeLabel() }}</small></th><td>{{ $holiday->date->format('D, M j') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            </div>
        </div>

        <div class="payslip-columns">
            <section class="payslip-section">
                <h3>Earnings</h3>
                <table class="payslip-table">
                    <tbody>
                        @foreach($payslip['earnings'] as $label)
                            <tr><th>{{ $label }}</th><td class="payslip-pending">Computed by Payroll</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="payslip-section">
                <h3>Deductions</h3>
                <table class="payslip-table">
                    <tbody>
                        @foreach($payslip['deductions'] as $label)
                            <tr><th>{{ $label }}</th><td class="payslip-pending">Computed by Payroll</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        </div>

        <div class="payslip-net">
            <span>Net pay</span>
            <strong class="payslip-pending">Computed by Payroll</strong>
        </div>

        <section class="payslip-section">
            <h3>Daily record</h3>
            <div class="table-responsive">
                <table class="payslip-table payslip-daily">
                    <thead><tr><th>Date</th><th>Regular</th><th>Overtime</th><th>Late</th><th>Undertime</th></tr></thead>
                    <tbody>
                        @foreach($payslip['entries'] as $entry)
                            <tr>
                                <td>{{ $entry->work_date->format('D, M j') }}</td>
                                <td>{{ $formatMinutes($entry->regular_minutes) }}</td>
                                <td>{{ $entry->overtime_minutes ? $formatMinutes($entry->overtime_minutes) : '—' }}</td>
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
@endsection
