@php
    /*
     * The schedule, read one day at a time.
     *
     * The grid below answers "who is working this month". This answers "what am
     * I doing today, and who is on with me", which is the question somebody
     * asks standing in a corridor with one hand free.
     *
     * The Month / Week / List switch is the page's own, carried across rather
     * than replaced: the phone defaults to this day view, and the three grids
     * stay one tap away in the same control the desktop uses.
     */
    $phone = $myPhoneSchedule;
    $day = $phone['day'];
@endphp

<div class="schedule-phone">
    <nav class="schedule-phone-views" aria-label="Calendar view">
        @foreach (['month' => 'Month', 'week' => 'Week', 'list' => 'List'] as $viewKey => $viewLabel)
            <a
                href="{{ $queryFor(['view' => $viewKey]) }}"
                @class(['is-active' => $calendarView === $viewKey])
                @if ($calendarView === $viewKey) aria-current="page" @endif
            >{{ $viewLabel }}</a>
        @endforeach
    </nav>

    <section class="panel schedule-phone-strip" aria-label="This week">
        <div class="schedule-phone-strip-head">
            <strong>{{ $phone['month_label'] }}</strong>
            @unless ($phone['is_today'])
                <a class="schedule-phone-today" href="{{ $queryFor(['date' => $phone['today_date']]) }}">Today</a>
            @endunless
        </div>

        <ol class="schedule-phone-days">
            @foreach ($phone['week'] as $weekDay)
                <li>
                    <a
                        href="{{ $queryFor(['date' => $weekDay['date']]) }}"
                        @class([
                            'schedule-phone-day',
                            'is-focus' => $weekDay['is_focus'],
                            'is-today' => $weekDay['is_today'],
                            'is-past' => $weekDay['is_past'],
                        ])
                        @if ($weekDay['is_focus']) aria-current="date" @endif
                    >
                        <span class="schedule-phone-letter">{{ $weekDay['letter'] }}</span>
                        <span class="schedule-phone-number">{{ $weekDay['day'] }}</span>
                        {{-- One dot, three meanings: rostered, a rest day, or
                             nothing planned. Shape as well as colour, because a
                             dot alone is the only cue this row has. --}}
                        <span @class(['schedule-phone-dot', 'is-'.$weekDay['state']]) aria-hidden="true"></span>
                        <span class="visually-hidden">
                            {{ match ($weekDay['state']) {
                                'shift' => 'Rostered',
                                'day-off' => 'Rest day',
                                default => 'Nothing scheduled',
                            } }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="panel schedule-phone-detail" aria-labelledby="schedulePhoneDay">
        <div class="schedule-phone-detail-head">
            <h2 id="schedulePhoneDay">{{ $day['label'] }}</h2>
            @if ($day['is_today'])
                <span class="status-badge status-success"><span class="status-dot"></span>Today</span>
            @endif
        </div>

        @if ($day['state'] === 'shift')
            <div class="schedule-phone-shift" @if ($day['color']) style="--shift-color: {{ $day['color'] }}" @endif>
                <span class="schedule-phone-shift-bar" aria-hidden="true"></span>
                <div>
                    <p class="schedule-phone-hours">{{ $day['hours'] }}</p>
                    <p class="schedule-phone-where">
                        {{ $day['shift_name'] }}@if ($day['room']) · {{ $day['room'] }}@endif
                    </p>
                </div>
            </div>

            @if ($phone['colleagues'] !== [])
                <div class="schedule-phone-with">
                    <p class="schedule-phone-with-title">On with you</p>
                    <ul>
                        @foreach ($phone['colleagues'] as $colleague)
                            <li class="schedule-phone-mate">
                                <span class="avatar avatar-sm" aria-hidden="true">{{ $colleague['initials'] }}</span>
                                <span>
                                    <b>{{ $colleague['short_name'] }}</b>
                                    <small>{{ $colleague['position'] }}</small>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @unless ($day['is_past'])
                <a class="schedule-phone-swap" href="{{ route('schedule-preferences.index') }}#shift-swaps">
                    <x-icon name="swap" /> Ask someone to swap
                </a>
            @endunless
        @elseif ($day['state'] === 'day-off')
            <p class="schedule-phone-empty"><x-icon name="check-circle" /> Rest day — nothing rostered.</p>
        @else
            <p class="schedule-phone-empty"><x-icon name="calendar" /> Nothing scheduled for this day.</p>
        @endif
    </section>

    @if ($phone['upcoming'] !== [])
        <section class="panel schedule-phone-next" aria-labelledby="schedulePhoneNext">
            <h2 class="schedule-phone-next-title" id="schedulePhoneNext">Next two weeks</h2>
            <ul>
                @foreach ($phone['upcoming'] as $row)
                    <li>
                        <a href="{{ $queryFor(['date' => $row['date']]) }}">
                            <span class="schedule-phone-next-day">{{ $row['label'] }}</span>
                            <span class="schedule-phone-next-what">{{ $row['what'] }}@if ($row['shift_name']) · {{ $row['shift_name'] }}@endif</span>
                            <span @class(['schedule-phone-dot', 'is-'.$row['state']]) aria-hidden="true"></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
