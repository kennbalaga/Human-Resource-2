@php
    $formatMinutes = fn (int $minutes) => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    $formatDays = fn (float $days) => rtrim(rtrim(number_format($days, 1), '0'), '.');
    $employee = $payslip['employee'];
    $period = $payslip['period'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $payslip['number'] }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { margin: 0; color: #1b2923; font-family: 'DejaVu Sans', sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        .header { border-bottom: 2px solid #176b43; margin-bottom: 10px; }
        .header td { padding: 0 0 8px; vertical-align: middle; }
        .header .logo { width: 56px; }
        .header .flags { text-align: right; }
        .header h1 { margin: 0; font-size: 18px; color: #176b43; }
        .header p { margin: 2px 0 0; color: #55635b; }
        .status { padding: 3px 8px; border: 1px solid #176b43; border-radius: 10px; color: #176b43; font-size: 9px; }
        .status-partial { border-color: #9a6b00; color: #9a6b00; }
        .confidential { display: block; margin-bottom: 5px; color: #b42318; font-size: 10px; font-weight: bold; letter-spacing: .12em; }
        .notice { margin-bottom: 10px; padding: 6px 8px; border: 1px solid #e7c873; background: #fdf6e3; color: #6b4f00; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .label { width: 16%; color: #55635b; }
        .grid { margin-top: 14px; }
        .grid > tbody > tr > td, .grid > tr > td { width: 50%; vertical-align: top; padding: 0 8px 0 0; }
        .grid > tbody > tr > td + td, .grid > tr > td + td { padding: 0 0 0 8px; }
        h2 { margin: 0 0 6px; padding-bottom: 3px; border-bottom: 1px solid #c9d3cd; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #55635b; }
        .spaced { margin-top: 12px; }
        .lines td { padding: 4px 0; border-bottom: 1px solid #e8ede9; }
        .lines td.value { text-align: right; }
        .lines small { display: block; color: #55635b; font-size: 8.5px; }
        .pending { color: #7a6a3a; font-style: italic; }
        .net { margin-top: 12px; padding: 8px 10px; background: #eaf7f0; }
        .net td { font-size: 12px; font-weight: bold; }
        .daily { margin-top: 14px; }
        .daily th { padding: 4px; border-bottom: 1px solid #c9d3cd; color: #55635b; font-size: 8.5px; text-align: left; text-transform: uppercase; }
        .daily td { padding: 4px; border-bottom: 1px solid #e8ede9; }
        .footnote { margin-top: 16px; color: #55635b; font-size: 8.5px; }
    </style>
</head>
<body>
    {{-- Embedded rather than linked: dompdf would otherwise need remote or
         chroot access to fetch it, and its SVG support drops the evenodd
         cutouts the on-page mark relies on, so the PNG tile is used here. --}}
    <table class="header">
        <tr>
            <td class="logo"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/icons/icon-192.png'))) }}" width="46" height="46" alt=""></td>
            <td>
                <h1>Payslip</h1>
                <p>{{ config('branding.organization') }}</p>
            </td>
            <td class="flags">
                <span class="confidential">CONFIDENTIAL</span>
                @if($payslip['complete'])
                    <span class="status">Sent to payroll</span>
                @else
                    <span class="status status-partial">Partial</span>
                @endif
            </td>
        </tr>
    </table>

    @unless($payslip['complete'])
        <div class="notice">
            {{ $payslip['weeks']['pending'] }} {{ str('timesheet')->plural($payslip['weeks']['pending']) }} in this period {{ $payslip['weeks']['pending'] === 1 ? 'is' : 'are' }} not approved yet. {{ $payslip['days']['pending'] }} {{ str('day')->plural($payslip['days']['pending']) }} will be added once approved.
        </div>
    @endunless

    <table class="meta">
        <tr><td class="label">Employee</td><td>{{ $employee->full_name }}</td><td class="label">Payslip no.</td><td>{{ $payslip['number'] }}</td></tr>
        <tr><td class="label">Employee ID</td><td>{{ $employee->employee_number }}</td><td class="label">Pay period</td><td>{{ $period->start->format('M j, Y') }} – {{ $period->end->format('M j, Y') }}</td></tr>
        <tr><td class="label">Department</td><td>{{ $employee->department?->name ?? '—' }}</td><td class="label">Position</td><td>{{ $employee->position?->title ?? '—' }}</td></tr>
        <tr><td class="label">Last approved</td><td colspan="3">{{ $payslip['approved_at']?->format('M j, Y') ?? '—' }}@if($payslip['approved_by']) by {{ $payslip['approved_by'] }}@endif</td></tr>
    </table>

    <table class="grid">
        <tr>
            <td>
                <h2>Attendance</h2>
                <table class="lines">
                    <tr><td>Days scheduled</td><td class="value">{{ $payslip['days']['scheduled'] }}</td></tr>
                    <tr><td>Days worked</td><td class="value">{{ $payslip['days']['worked'] }}</td></tr>
                    <tr><td>Days on leave</td><td class="value">{{ $formatDays($payslip['days']['leave']) }}</td></tr>
                    @if($payslip['days']['pending'])
                        <tr><td>Awaiting approval</td><td class="value">{{ $payslip['days']['pending'] }}</td></tr>
                    @endif
                    <tr><td>Absences</td><td class="value">{{ $payslip['days']['absent'] }}</td></tr>
                    <tr><td>Regular hours</td><td class="value">{{ $formatMinutes($payslip['minutes']['regular']) }}</td></tr>
                    <tr><td>Overtime</td><td class="value">{{ $formatMinutes($payslip['minutes']['overtime']) }}</td></tr>
                    <tr><td>Late</td><td class="value">{{ $payslip['minutes']['late'] }} min</td></tr>
                    <tr><td>Undertime</td><td class="value">{{ $payslip['minutes']['undertime'] }} min</td></tr>
                </table>
            </td>
            <td>
                <h2>Leave</h2>
                <table class="lines">
                    @forelse($payslip['leave'] as $leave)
                        <tr><td>{{ $leave['type'] }}<small>{{ $leave['category'] }} · {{ $leave['from']->format('M j') }}@unless($leave['from']->isSameDay($leave['to']))–{{ $leave['to']->format('M j') }}@endunless</small></td><td class="value">{{ $formatDays($leave['days']) }} {{ str('day')->plural($leave['days']) }}</td></tr>
                    @empty
                        <tr><td>No approved leave in this period.</td><td></td></tr>
                    @endforelse
                </table>

                <h2 class="spaced">Holidays</h2>
                <table class="lines">
                    @forelse($payslip['holidays'] as $holiday)
                        <tr><td>{{ $holiday->name }}<small>{{ $holiday->typeLabel() }}</small></td><td class="value">{{ $holiday->date->format('D, M j') }}</td></tr>
                    @empty
                        <tr><td>No holidays in this period.</td><td></td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <td>
                <h2>Earnings</h2>
                <table class="lines">
                    @foreach($payslip['earnings'] as $label)
                        <tr><td>{{ $label }}</td><td class="value pending">Computed by Payroll</td></tr>
                    @endforeach
                </table>
            </td>
            <td>
                <h2>Deductions</h2>
                <table class="lines">
                    @foreach($payslip['deductions'] as $label)
                        <tr><td>{{ $label }}</td><td class="value pending">Computed by Payroll</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table class="net"><tr><td>Net pay</td><td style="text-align:right" class="pending">Computed by Payroll</td></tr></table>

    <table class="daily">
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

    <p class="footnote">
        CONFIDENTIAL — for the named employee and HR only. This payslip shows the approved attendance payroll pays on.
        Earnings, deductions and net pay are computed by Payroll, outside this system. Generated {{ now()->format('M j, Y g:i A') }}.
    </p>
</body>
</html>
