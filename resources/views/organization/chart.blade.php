@extends('layouts.app')

@section('title', 'Org chart')

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow">Organization</p>
            <h1>Reporting lines</h1>
            <p>Who reports to whom, built from each employee's recorded supervisor.</p>
        </div>
        <a class="btn btn-outline-primary dashboard-action" href="{{ route('employees.index') }}">
            <x-icon name="users" /> Employee directory
        </a>
    </section>

    @include('partials.organization-tabs')

    <section class="panel org-chart-panel" data-org-chart>
        <div class="panel-header">
            <div>
                <p class="panel-kicker">{{ number_format($chart['total']) }} people on the chart</p>
                <h2>Hospital reporting structure</h2>
            </div>
            <div class="org-chart-actions">
                <button class="btn btn-light" type="button" data-org-expand-all>Expand all</button>
                <button class="btn btn-light" type="button" data-org-collapse-all>Collapse all</button>
            </div>
        </div>

        @if ($chart['roots'] === [])
            <div class="empty-state">
                <x-icon name="users" />
                <strong>No reporting lines recorded yet</strong>
                <span>Set a supervisor on an employee's profile and they will appear here.</span>
            </div>
        @else
            <div class="org-chart-scroll">
                <ul class="org-tree">
                    @foreach ($chart['roots'] as $root)
                        @include('organization.partials.chart-node', ['node' => $root])
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($chart['unplaced'] > 0)
            {{--
                Only reachable if supervisor_id forms a loop with no root
                (A supervises B, B supervises A). Nothing in the app creates
                one, but the column is a self-referencing FK with no
                database-level guard, so the count is surfaced rather than
                those people silently vanishing from the chart.
            --}}
            <p class="org-chart-footnote">
                <x-icon name="alert" />
                {{ $chart['unplaced'] }} {{ Str::plural('employee', $chart['unplaced']) }}
                could not be placed because their reporting line forms a loop.
                Correct the supervisor on those profiles to show them here.
            </p>
        @endif
    </section>
@endsection
