@props(['exceptions'])

{{-- The names behind two of the shift board's numbers. "1 missing" tells a
     charge nurse there is a problem; it does not tell them who to ring. --}}
<aside class="panel today-exceptions" id="today-exceptions" aria-labelledby="today-exceptions-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Today</p>
            <h2 id="today-exceptions-title">Who is not on the floor</h2>
        </div>
        <span class="today-exceptions-asof">as of {{ $exceptions['as_of'] }}</span>
    </div>

    <section class="exception-block" aria-labelledby="exception-unaccounted-title">
        <header class="exception-block-header">
            <h3 id="exception-unaccounted-title">Unaccounted</h3>
            <span @class(['exception-count', 'is-alert' => $exceptions['unaccounted_total'] > 0])>
                {{ number_format($exceptions['unaccounted_total']) }}
            </span>
        </header>

        @if ($exceptions['unaccounted_total'] < 1)
            <p class="exception-empty">
                @if ($exceptions['settled'])
                    Everyone rostered on a shift that has opened is either clocked in or on approved leave.
                @else
                    {{-- Not the same statement as "nobody is missing": no shift has been
                         open long enough for an absence to mean anything yet. --}}
                    No shift has passed its check-in window yet. Absences appear here once one has.
                @endif
            </p>
        @else
            <ul class="exception-list">
                @foreach ($exceptions['unaccounted'] as $person)
                    <li>
                        <a class="exception-item" href="{{ $person['url'] }}">
                            <span class="exception-item-main">
                                <strong>{{ $person['name'] }}</strong>
                                <span class="exception-item-meta">
                                    @if ($person['shift']){{ $person['shift'] }}@endif
                                    @if ($person['department']) · {{ $person['department'] }}@endif
                                </span>
                            </span>
                            @if ($person['span'])
                                <span class="exception-item-tag">{{ $person['span'] }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($exceptions['unaccounted_total'] > count($exceptions['unaccounted']))
                <a class="exception-more" href="{{ route('attendance.reports.index', ['date_from' => $exceptions['date'], 'date_to' => $exceptions['date']]) }}">
                    {{ number_format($exceptions['unaccounted_total'] - count($exceptions['unaccounted'])) }} more <x-icon name="chevron-right" />
                </a>
            @endif
        @endif
    </section>

    <section class="exception-block" aria-labelledby="exception-leave-title">
        <header class="exception-block-header">
            <h3 id="exception-leave-title">On approved leave</h3>
            <span class="exception-count">{{ number_format($exceptions['on_leave_total']) }}</span>
        </header>

        @if ($exceptions['on_leave_total'] < 1)
            <p class="exception-empty">Nobody is on approved leave today.</p>
        @else
            <ul class="exception-list">
                @foreach ($exceptions['on_leave'] as $person)
                    <li>
                        <a class="exception-item" href="{{ $person['url'] }}">
                            <span class="exception-item-main">
                                <strong>{{ $person['name'] }}</strong>
                                <span class="exception-item-meta">
                                    {{ $person['leave_type'] }}
                                    @if ($person['department']) · {{ $person['department'] }}@endif
                                </span>
                            </span>
                            <span class="exception-item-tag">{{ $person['until_label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($exceptions['on_leave_total'] > count($exceptions['on_leave']))
                <a class="exception-more" href="{{ route('leaves.index', ['status' => 'approved', 'date' => $exceptions['date']]) }}">
                    {{ number_format($exceptions['on_leave_total'] - count($exceptions['on_leave'])) }} more <x-icon name="chevron-right" />
                </a>
            @endif
        @endif
    </section>

    <a href="{{ route('attendance.reports.index', ['date_from' => $exceptions['date'], 'date_to' => $exceptions['date']]) }}" class="panel-footer-link">
        Open today's attendance report <x-icon name="chevron-right" />
    </a>
</aside>
