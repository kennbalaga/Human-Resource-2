@extends('layouts.app')

@section('title', 'Burnout Risk · Workforce Analytics')

@section('content')
    @php
        $hasFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
    @endphp

    <section class="page-heading workforce-heading">
        <div>
            <p class="eyebrow">Workforce Intelligence</p>
            <h1>Workforce Analytics</h1>
            <p>Who is carrying too much, from the last {{ (int) config('burnout.window_days') }} days of hours, rest and leave. Assessed {{ $asOf->format('M j, Y') }}.</p>
        </div>
    </section>

    @include('analytics._tabs')

    {{-- Live: every change refreshes the results below in place (see
         resources/js/burnout-risk.js). The button is only for a browser
         running without scripts, which submits the form the ordinary way. --}}
    <section class="panel workforce-filter-panel">
        <form method="GET" action="{{ route('analytics.burnout-risk') }}" class="workforce-filters analytics-filters" data-burnout-filters>
            <label><span>Department</span><select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>@endforeach</select></label>
            <label><span>Risk level</span><select name="level"><option value="">Every level</option>@foreach(['high' => 'High', 'moderate' => 'Moderate', 'low' => 'Low'] as $value => $label)<option value="{{ $value }}" @selected(($filters['level'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label><span>Trend</span><select name="trend"><option value="">Any trend</option>@foreach(['rising' => 'Rising', 'steady' => 'Steady', 'easing' => 'Easing', 'new' => 'New (no earlier period)'] as $value => $label)<option value="{{ $value }}" @selected(($filters['trend'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            <button class="btn btn-primary" type="submit" data-burnout-filters-submit>Update list</button>
            <a class="btn btn-light" href="{{ route('analytics.burnout-risk') }}" data-burnout-filters-clear @unless ($hasFilters) hidden @endunless>Clear</a>
            <span class="burnout-filters-status" data-burnout-filters-status role="status" aria-live="polite"></span>
        </form>
    </section>

    <div class="burnout-results" id="burnout-risk-results" data-burnout-results>
        @include('analytics._burnout-risk-results')
    </div>

    <section class="analytics-grid">
        <article class="panel analytics-panel analytics-wide burnout-policy">
            <div class="panel-header"><div><p class="panel-kicker">How to read this</p><h2>What this indicator is, and is not</h2></div></div>
            <div class="burnout-policy-body">
                <p>{{ \App\Services\Burnout\BurnoutRiskService::DISCLAIMER }}</p>
                <p>Each employee sees their own level and what is driving it on their dashboard. Department heads see their own unit here; HR sees every department.</p>
                <p>Employees at <strong>high</strong> risk are kept to {{ config('burnout.protection.days_off_per_week') }} rest days, {{ config('burnout.protection.max_weekly_hours') }} hours and {{ config('burnout.protection.max_night_shifts_per_week') }} night shifts a week by the bulk fill and rotation assistants, and are ranked after everyone else in single-shift recommendations. A reviewer can still schedule past those limits on the roster board, with a written justification.</p>
            </div>
        </article>
    </section>
@endsection
