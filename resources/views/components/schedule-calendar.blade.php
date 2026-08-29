@props(['calendar', 'overview'])

{{-- The schedule rail beside the attendance chart: the week at a glance, then
     the day itself as a timeline.

     This is where the standalone shift-overview panel went. The full coverage
     view — per-pool bars, phases, the data table — lives in the schedules
     module, which every row here links into; carrying it on the dashboard as
     well only meant the same roster was drawn twice on one screen. --}}
@php
    $shifts = collect($overview['shifts']);
    // A pool nobody was rostered onto still belongs on the timeline: an empty
    // Night shift is exactly what a charge nurse needs to catch. It just says so
    // in one line instead of carrying counts that are all zero.
    $rosteredToday = $overview['rostered'];
@endphp

<aside class="panel schedule-calendar" aria-labelledby="schedule-calendar-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Scheduling</p>
            <h2 id="schedule-calendar-title">Calendar</h2>
        </div>
        <span class="schedule-calendar-month">{{ $calendar['month_label'] }}</span>
    </div>

    <ol class="week-strip" aria-label="This week · {{ $calendar['range_label'] }}">
        @foreach ($calendar['days'] as $day)
            <li @class([
                'week-strip-day',
                'is-today' => $day['is_today'],
                'is-weekend' => $day['is_weekend'],
                'is-past' => $day['is_past'],
            ])>
                <a href="{{ route('schedules.index', ['date' => $day['date']]) }}"
                   @if ($day['is_today']) aria-current="date" @endif
                   aria-label="{{ $day['weekday'] }} {{ $day['number'] }} — {{ $day['assigned'] }} rostered">
                    <span class="week-strip-name" aria-hidden="true">{{ $day['weekday'] }}</span>
                    <span class="week-strip-number" aria-hidden="true">{{ $day['number'] }}</span>
                    {{-- Presence, not quantity. The number is in the label the
                         screen reader gets; on screen a day either has a roster
                         or it does not, and seven counts crammed under seven
                         dates is unreadable at this width. --}}
                    <span @class(['week-strip-dot', 'is-empty' => $day['assigned'] < 1]) aria-hidden="true"></span>
                </a>
            </li>
        @endforeach
    </ol>

    <div class="schedule-today">
        <p class="schedule-today-head">
            <span>Today’s schedule</span>
            <small>as of {{ $overview['as_of'] }}</small>
        </p>

        @if (! $rosteredToday)
            <div class="compact-empty-state schedule-today-empty">
                <x-icon name="calendar" />
                <p>Nobody is rostered today. Publish a roster to see the day fill in here.</p>
            </div>
        @else
            <ol class="schedule-timeline">
                @foreach ($shifts as $shift)
                    @php
                        // The same people either way — only the wording changes, so
                        // a shift minutes old reads calm and a late one does not.
                        $absentLabel = $shift['awaiting'] ? 'not yet in' : 'missing';
                        $isLive = in_array($shift['phase'], ['starting', 'active'], true);

                        // Built here rather than as inline @if runs in the sentence
                        // below: Blade only compiles a directive whose @ follows a
                        // non-word character, so "clocked in@if(...)" would have
                        // been left in the page as literal text.
                        $note = [number_format($shift['clocked_in']).' of '.number_format($shift['assigned']).' clocked in'];

                        if ($shift['missing'] > 0) {
                            $note[] = number_format($shift['missing']).' '.$absentLabel;
                        }

                        if ($shift['on_leave'] > 0) {
                            $note[] = number_format($shift['on_leave']).' on leave';
                        }
                    @endphp
                    <li @class(['schedule-timeline-item', 'is-live' => $isLive, 'is-quiet' => $shift['assigned'] < 1])>
                        <p class="schedule-timeline-time">{{ $shift['start_time'] }}</p>

                        <article class="schedule-timeline-card">
                            <header>
                                <h3>{{ $shift['name'] }}</h3>
                                @if ($isLive)
                                    <span class="schedule-timeline-tag">{{ $shift['phase_label'] }}</span>
                                @endif
                            </header>

                            <p class="schedule-timeline-span">
                                {{ $shift['span_label'] }}@if ($shift['crosses_midnight']) · overnight @endif
                            </p>

                            @if ($shift['assigned'] < 1)
                                <p class="schedule-timeline-note">Nobody rostered.</p>
                            @else
                                <p class="schedule-timeline-note">{{ implode(', ', $note) }}.</p>

                                <div
                                    class="schedule-timeline-bar"
                                    role="img"
                                    aria-label="{{ $shift['assigned'] }} assigned, {{ $shift['clocked_in'] }} clocked in, {{ $shift['on_leave'] }} on approved leave, {{ $shift['missing'] }} {{ $absentLabel }}."
                                >
                                    @foreach ([
                                        ['key' => 'clocked-in', 'count' => $shift['clocked_in']],
                                        ['key' => 'on-leave', 'count' => $shift['on_leave']],
                                        ['key' => $shift['awaiting'] ? 'awaiting' : 'missing', 'count' => $shift['missing']],
                                    ] as $segment)
                                        @continue($segment['count'] < 1)
                                        <i class="schedule-timeline-fill is-{{ $segment['key'] }}" style="flex-grow: {{ $segment['count'] }}"></i>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <a href="{{ route('schedules.index', ['date' => $overview['date']]) }}" class="panel-footer-link">
        Open shift &amp; schedule management <x-icon name="chevron-right" />
    </a>
</aside>
