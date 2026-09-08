@extends('layouts.app')

@section('title', $report->heading())

@section('content')
    @include('reports._heading')
    @include('reports._filters', ['route' => 'reports.show', 'routeParameters' => ['report' => $report->key()]])

    <section class="panel attendance-report-table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">{{ $report->rangeLabel() }}</p>
                <h2>{{ \Carbon\Carbon::parse($filters['date_from'])->format('M j, Y') }} – {{ \Carbon\Carbon::parse($filters['date_to'])->format('M j, Y') }}</h2>
            </div>
            <span class="history-caption">{{ number_format($records->total()) }} {{ str('record')->plural($records->total()) }}</span>
        </div>

        <div class="table-responsive">
            <table class="dashboard-table report-table table-stack">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th @class(['text-right' => $column->numeric])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            @foreach ($columns as $column)
                                @php($value = $column->resolve($record))
                                <td data-label="{{ $column->label }}" @class(['text-right' => $column->numeric])>
                                    {{-- Statuses are the one value worth styling generically: every
                                         report has at least one, and the badge component already knows
                                         the vocabulary. --}}
                                    @if (str_contains(strtolower($column->label), 'status') || $column->label === 'Approval')
                                        <x-status-badge :status="$value ?? 'unknown'" />
                                    @else
                                        {{ $value === null || $value === '' ? '—' : $value }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}" class="empty-table-cell">
                                <x-icon name="report" />
                                <strong>No matching records</strong>
                                <span>Try changing the date range or filters.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($records->hasPages())
            <div class="report-pagination">{{ $records->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif
    </section>
@endsection
