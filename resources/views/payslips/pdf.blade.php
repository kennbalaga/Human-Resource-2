@use('App\Support\Duration')
@php
    $employee = $payslip['employee'];
    $period = $payslip['period'];
    $days = $payslip['days'];
    $minutes = $payslip['minutes'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $payslip['number'] }}</title>
    {{--
        Written against what dompdf can actually render, which is why this file
        looks nothing like the screen's stylesheet: tables carry the layout
        because there is no flex and no grid, and every colour is a literal
        because there are no custom properties. Each hex is annotated with the
        token it is the value of, so a change in tokens.css has a findable
        follow-up here. A printed document has no theme, so these are the
        light-theme values and there is no dark counterpart.
    --}}
    <style>
        /* The deep bottom margin is not padding: it reserves the strip
           PayslipController draws the running footer into with page_text(). */
        @page { margin: 26px 36px 48px; }
        /* dompdf's default_font is serif, so the family is stated outright.
           DejaVu Sans is the bundled face that has the en and em dashes. */
        body { margin: 0; color: #14211d; font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }

        /* Masthead. The employee is the subject; the document names itself on
           the line beneath, which on screen is the page heading. */
        .masthead { border-bottom: 2px solid #059669; }                      /* --hr-primary */
        .masthead td { padding: 0 0 7px; vertical-align: middle; }
        .masthead .logo { width: 54px; }
        .masthead .kicker { margin: 0; color: #047857; font-size: 8px; font-weight: bold; letter-spacing: .1em; text-transform: uppercase; }  /* --hr-primary-text */
        .masthead h1 { margin: 1px 0 0; font-size: 15px; }
        .masthead .docline { margin: 2px 0 0; color: #64748b; font-size: 9.5px; }  /* --hr-muted */
        .masthead .flags { text-align: right; vertical-align: top; }
        .badge { padding: 3px 8px; border-radius: 9px; color: #2e6699; background: #eaf1f8; font-size: 8.5px; font-weight: bold; }  /* --hr-info-text on a flattened --hr-tint-info */
        .badge-partial { color: #8a5f06; background: #fff6dd; }              /* --hr-warning-text / -soft */

        .notice { margin-top: 10px; padding: 6px 8px; border: 1px solid #ecd9a4; background: #fff6dd; color: #8a5f06; font-size: 9.5px; }  /* --hr-warning-line */

        /* Identity: the same six cells as the screen, 3 x 2. */
        .meta { margin-top: 9px; page-break-inside: avoid; }
        .meta td { width: 33.33%; padding: 4px 9px 4px 0; vertical-align: top; }
        .meta .label { display: block; color: #64748b; font-size: 7.5px; font-weight: bold; letter-spacing: .05em; text-transform: uppercase; }
        .meta .hint { display: block; color: #5d6b72; font-size: 8px; }      /* --hr-subtle */

        /* Only short content goes in a two-column layout table: a nested table
           that splits across a page break reflows badly, so each one is kept
           whole instead. */
        .cols { margin-top: 9px; page-break-inside: avoid; }
        .cols > tbody > tr > td { width: 50%; padding: 0 9px 0 0; vertical-align: top; }
        .cols > tbody > tr > td.right { padding: 0 0 0 9px; }
        h2 { margin: 0 0 4px; padding-bottom: 3px; border-bottom: 1px solid #e3e8e8; color: #64748b; font-size: 8.5px; letter-spacing: .05em; text-transform: uppercase; }
        .lines td { padding: 3px 0; border-bottom: 1px solid #f1f4f4; }    /* --hr-surface-3 */
        .lines td.value { text-align: right; }
        .lines small { display: block; color: #5d6b72; font-size: 8px; }
        .placeholder { color: #879595; text-align: right; }                  /* --hr-placeholder */
        .note { margin: 6px 0 0; color: #5d6b72; font-size: 8px; }

        .net { margin-top: 9px; border: 1px solid #a7f3d0; background: #ecfdf5; page-break-inside: avoid; }  /* --hr-primary-line / -soft */
        .net td { padding: 6px 10px; font-size: 11.5px; font-weight: bold; }
        .net td.right { text-align: right; }

        /* The daily record is a TOP-LEVEL table with a real thead, which is
           what makes dompdf repeat the head on every page it splits onto:
           FrameDecorator\Table::split() deep-copies the table-header-group. */
        .daily { margin-top: 10px; }
        .daily thead { display: table-header-group; }
        .daily tr { page-break-inside: avoid; }
        .daily th { padding: 3px 6px; border-bottom: 1px solid #7b8a8a; color: #64748b; font-size: 7.5px; letter-spacing: .04em; text-align: left; text-transform: uppercase; }  /* --hr-border-strong */
        .daily td { padding: 3px 6px; border-bottom: 1px solid #f1f4f4; font-size: 10px; }
        .daily th.num, .daily td.num { text-align: right; }
        .daily tbody tr.alt td { background: #fafbfb; }

        .footnote { margin: 9px 0 0; padding-top: 5px; border-top: 1px solid #e3e8e8; color: #5d6b72; font-size: 8px; }
    </style>
</head>
<body>
    {{-- Embedded rather than linked: dompdf would otherwise need remote or
         chroot access to fetch it, and its SVG support drops the evenodd
         cutouts the on-page mark relies on, so the PNG tile is used here. --}}
    <table class="masthead">
        <tr>
            <td class="logo"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/icons/icon-192.png'))) }}" width="46" height="46" alt=""></td>
            <td>
                <p class="kicker">{{ config('branding.organization') }}</p>
                <h1>{{ $employee->full_name }}</h1>
                <p class="docline">Payslip · {{ $period->label() }} · {{ $payslip['number'] }}</p>
            </td>
            <td class="flags">
                @if($payslip['complete'])
                    <span class="badge">Sent to payroll</span>
                @else
                    <span class="badge badge-partial">Partial</span>
                @endif
            </td>
        </tr>
    </table>

    @unless($payslip['complete'])
        <div class="notice">
            {{ $payslip['weeks']['pending'] }} {{ str('timesheet')->plural($payslip['weeks']['pending']) }} in this period {{ $payslip['weeks']['pending'] === 1 ? 'is' : 'are' }} not approved yet. {{ $days['pending'] }} {{ str('day')->plural($days['pending']) }} will be added once approved.
        </div>
    @endunless

    <table class="meta">
        <tr>
            <td><span class="label">Employee ID</span>{{ $employee->employee_number }}</td>
            <td><span class="label">Department</span>{{ $employee->department?->name ?? '—' }}</td>
            <td><span class="label">Position</span>{{ $employee->position?->title ?? '—' }}</td>
        </tr>
        <tr>
            <td><span class="label">Pay period</span>{{ $period->start->format('M j') }} – {{ $period->end->format('M j, Y') }}</td>
            {{-- One expression rather than a trailing @if: Blade only reads a
                 directive whose @ does not follow a word character. --}}
            <td><span class="label">Timesheets</span>{{ $payslip['weeks']['approved'] }} approved{{ $payslip['weeks']['pending'] ? ' · '.$payslip['weeks']['pending'].' pending' : '' }}</td>
            <td><span class="label">Last approved</span>{{ $payslip['approved_at']?->format('M j, Y') ?? '—' }}@if($payslip['approved_by'])<span class="hint">by {{ $payslip['approved_by'] }}</span>@endif</td>
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td>
                <h2>Days</h2>
                <table class="lines">
                    <tr><td>Scheduled</td><td class="value">{{ $days['scheduled'] }}</td></tr>
                    <tr><td>Worked</td><td class="value">{{ $days['worked'] }}</td></tr>
                    <tr><td>On leave</td><td class="value">{{ Duration::days($days['leave']) }}</td></tr>
                    @if($days['pending'])
                        <tr><td>Awaiting approval</td><td class="value">{{ $days['pending'] }}</td></tr>
                    @endif
                    <tr><td>Absent</td><td class="value">{{ $days['absent'] }}</td></tr>
                </table>
            </td>
            <td class="right">
                <h2>Hours</h2>
                <table class="lines">
                    <tr><td>Regular</td><td class="value">{{ Duration::hm($minutes['regular']) }}</td></tr>
                    <tr><td>Overtime</td><td class="value">{{ Duration::hm($minutes['overtime']) }}</td></tr>
                    <tr><td>Late</td><td class="value">{{ $minutes['late'] }} min</td></tr>
                    <tr><td>Undertime</td><td class="value">{{ $minutes['undertime'] }} min</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="cols">
        <tr>
            <td>
                <h2>Leave</h2>
                <table class="lines">
                    @forelse($payslip['leave'] as $leave)
                        <tr><td>{{ $leave['type'] }}<small>{{ $leave['category'] }} · {{ $leave['from']->format('M j') }}@unless($leave['from']->isSameDay($leave['to']))–{{ $leave['to']->format('M j') }}@endunless</small></td><td class="value">{{ Duration::days($leave['days']) }} {{ str('day')->plural($leave['days']) }}</td></tr>
                    @empty
                        <tr><td>No approved leave in this period.</td><td></td></tr>
                    @endforelse
                </table>
            </td>
            <td class="right">
                <h2>Holidays</h2>
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

    {{-- The labels stay so the document still reads as a payslip; the dash is
         the placeholder and the note below is the one place the reason is
         written, rather than once per line. --}}
    <table class="cols">
        <tr>
            <td>
                <h2>Earnings</h2>
                <table class="lines">
                    @foreach($payslip['earnings'] as $label)
                        <tr><td>{{ $label }}</td><td class="placeholder">—</td></tr>
                    @endforeach
                </table>
            </td>
            <td class="right">
                <h2>Deductions</h2>
                <table class="lines">
                    @foreach($payslip['deductions'] as $label)
                        <tr><td>{{ $label }}</td><td class="placeholder">—</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <p class="note">Computed by Payroll. Amounts are released with the payout and are not held in this system — a dash means no figure yet, not zero.</p>

    <table class="net">
        <tr><td>Net pay</td><td class="right placeholder">—</td></tr>
    </table>

    <table class="daily">
        <thead>
            <tr>
                <th>Date</th>
                <th class="num">Regular</th>
                <th class="num">Overtime</th>
                <th class="num">Late</th>
                <th class="num">Undertime</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payslip['entries'] as $entry)
                <tr class="{{ $loop->even ? 'alt' : '' }}">
                    <td>{{ $entry->work_date->format('D, M j') }}</td>
                    <td class="num">{{ Duration::hm($entry->regular_minutes) }}</td>
                    <td class="num">{{ $entry->overtime_minutes ? Duration::hm($entry->overtime_minutes) : '—' }}</td>
                    <td class="num">{{ $entry->late_minutes ? $entry->late_minutes.'m' : '—' }}</td>
                    <td class="num">{{ $entry->undertime_minutes ? $entry->undertime_minutes.'m' : '—' }}</td>
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
