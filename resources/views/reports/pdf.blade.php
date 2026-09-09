<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { margin: 0; color: #1b2923; font-family: 'DejaVu Sans', sans-serif; font-size: 10px; }
        .report-header { margin-bottom: 14px; }
        .report-header h1 { margin: 0 0 2px; font-size: 16px; }
        .report-header p { margin: 0; color: #55635b; font-size: 10px; }
        .report-meta { margin-bottom: 12px; color: #55635b; font-size: 9.5px; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 5px 6px;
            border-bottom: 1px solid #c9d3cd;
            color: #55635b;
            font-size: 8.5px;
            letter-spacing: .03em;
            text-align: left;
            text-transform: uppercase;
        }
        tbody td { padding: 5px 6px; border-bottom: 1px solid #e8ede9; vertical-align: top; }
        tbody tr:nth-child(even) { background: #f6f9f7; }
        .text-right { text-align: right; }
        .empty-note { padding: 16px 0; color: #55635b; text-align: center; }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>{{ $title }}</h1>
        <p>{{ config('branding.organization') }}</p>
    </div>
    <div class="report-meta">
        {{ \Illuminate\Support\Carbon::parse($filters['date_from'])->format('M j, Y') }}
        &ndash;
        {{ \Illuminate\Support\Carbon::parse($filters['date_to'])->format('M j, Y') }}
        &middot; Generated {{ now()->format('M j, Y g:i A') }}
        &middot; {{ number_format(count($rows)) }} {{ str('record')->plural(count($rows)) }}
    </div>

    @if (empty($rows))
        <p class="empty-note">No matching records for the selected filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['text-right' => $column->numeric])>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_values($row) as $index => $value)
                            <td @class(['text-right' => $columns[$index]->numeric])>
                                {{ $value === null || $value === '' ? '—' : $value }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
