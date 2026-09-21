@extends('layouts.app')

@section('title', 'Room Board')

@section('content')
    @php
        $queryFor = fn (array $values) => route('schedules.rooms.index', array_merge(request()->except('page'), $values));
    @endphp

    <section class="page-heading schedule-heading">
        <div>
            <p class="eyebrow">Workforce Management</p>
            <h1>Room board</h1>
            <p>Give each rostered shift a place to stand. Duty itself is set on the schedule — this only says where.</p>
        </div>
        <div class="schedule-heading-actions">
            <a class="schedule-heading-link" href="{{ route('schedules.index') }}"><x-icon name="calendar" /> Schedules</a>
            <a class="schedule-heading-link" href="{{ route('rooms.index') }}"><x-icon name="layers" /> Manage rooms</a>
        </div>
    </section>

    @include('partials.organization-feedback')

    <form class="panel organization-filters room-board-toolbar" method="GET" action="{{ route('schedules.rooms.index') }}">
        <label>
            <span>Unit</span>
            <select name="department_id">
                @forelse($departments as $option)
                    <option value="{{ $option->id }}" @selected($department?->id === $option->id)>{{ $option->name }}</option>
                @empty
                    <option value="">No unit has rooms yet</option>
                @endforelse
            </select>
        </label>
        <label>
            <span>Date</span>
            <input type="date" name="date" value="{{ $date->toDateString() }}">
        </label>
        <div class="room-board-date-nav">
            <a class="btn btn-light btn-sm" href="{{ $queryFor(['date' => $previousDate]) }}" aria-label="Previous day"><x-icon name="arrow-left" /></a>
            @unless($isToday)<a class="btn btn-light btn-sm" href="{{ $queryFor(['date' => now(config('schedule.timezone'))->toDateString()]) }}">Today</a>@endunless
            <a class="btn btn-light btn-sm" href="{{ $queryFor(['date' => $nextDate]) }}" aria-label="Next day"><x-icon name="chevron-right" /></a>
            <noscript><button class="btn btn-primary btn-sm" type="submit">Show</button></noscript>
        </div>
    </form>

    @if($board === null)
        <section class="panel">
            <div class="compact-empty-state">
                <x-icon name="layers" />
                <p><strong>No rooms to place anybody in.</strong> Record this hospital's theatres, wards and clinic rooms first — then the day's roster can be given somewhere to stand.</p>
                <a class="btn btn-primary" href="{{ route('rooms.index') }}">Manage rooms</a>
            </div>
        </section>
    @else
        <section class="panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">{{ $department->name }}</p>
                    <h2>{{ $date->format('l, j F Y') }}</h2>
                </div>
            </div>

            <div class="room-board-body">
                <dl class="room-board-summary">
                    <div><dt>Rooms</dt><dd>{{ $board['summary']['rooms'] }}</dd></div>
                    <div><dt>Staffed</dt><dd>{{ $board['summary']['staffed_cells'] }}<span class="room-board-count"> / {{ $board['summary']['live_cells'] }}</span></dd></div>
                    <div><dt>Placed</dt><dd>{{ $board['summary']['placed'] }}</dd></div>
                    <div class="{{ $board['summary']['unplaced'] > 0 ? 'room-board-stat-warning' : 'room-board-stat-clear' }}"><dt>No room</dt><dd>{{ $board['summary']['unplaced'] }}</dd></div>
                    <div class="{{ $board['summary']['blockers'] > 0 ? 'room-board-stat-blocker' : 'room-board-stat-clear' }}"><dt>Blockers</dt><dd>{{ $board['summary']['blockers'] }}</dd></div>
                </dl>

                <div class="room-board-scroller">
                    <div class="room-board-grid" style="--room-board-shifts: {{ count($board['shifts']) }}">
                        <div class="room-board-head">
                            <div></div>
                            @foreach($board['shifts'] as $shift)
                                <div class="room-board-shift-head">
                                    <span class="room-board-shift-swatch" style="background: {{ $shift->color }}"></span>
                                    <strong>{{ $shift->name }}</strong>
                                    <span>{{ \Illuminate\Support\Carbon::parse($shift->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A') }}</span>
                                </div>
                            @endforeach
                        </div>

                        @forelse($board['rooms'] as $row)
                            @php($room = $row['room'])
                            <div class="room-board-row">
                                <div class="room-board-room {{ $room->isUsable() ? '' : 'is-out-of-service' }}">
                                    <div class="room-board-room-code">{{ $room->code }}</div>
                                    <div class="room-board-room-name">{{ $room->name }}</div>
                                    <div class="room-board-room-standard">
                                        <span class="room-board-chip-type">{{ $room->isUsable() ? $room->type_label : $room->status_label }}</span>
                                        @if($room->isUsable())
                                            <span>rank {{ $room->min_seniority_rank }}+@if($room->max_staff) · holds {{ $room->max_staff }}@endif</span>
                                        @endif
                                    </div>
                                </div>

                                @foreach($board['shifts'] as $shift)
                                    @php($cell = $row['cells'][$shift->id])
                                    @include('schedules.partials.room-cell', ['room' => $room, 'shift' => $shift, 'cell' => $cell, 'date' => $date, 'canManage' => $canManage])
                                @endforeach
                            </div>
                        @empty
                            <p class="compact-empty-state">This unit has no active rooms.</p>
                        @endforelse
                    </div>
                </div>

                <div class="room-board-legend">
                    <span><i class="room-board-swatch is-ok"></i> Meets its standard</span>
                    <span><i class="room-board-swatch is-short"></i> Short, or worth a look</span>
                    <span><i class="room-board-swatch is-blocked"></i> Cannot stand as it is</span>
                    <span><i class="room-board-swatch is-dark"></i> Room not running</span>
                </div>
            </div>
        </section>

        @include('schedules.partials.room-lists', ['board' => $board, 'date' => $date, 'canManage' => $canManage])

        @if($board['summary']['unplaced'] > 0)
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <p class="panel-kicker">Still to place</p>
                        <h2>{{ $board['summary']['unplaced'] }} on duty with no room</h2>
                    </div>
                </div>
                <div class="room-board-body">
                    @foreach($board['shifts'] as $shift)
                        @php($waiting = $board['unplaced'][$shift->id] ?? [])
                        @continue(empty($waiting))
                        <div class="room-board-unplaced-group">
                            <h3>{{ $shift->name }}</h3>
                            <div class="room-board-unplaced">
                                @foreach($waiting as $person)
                                    <span class="room-board-person {{ $person['on_leave'] ? 'is-on-leave' : '' }}" title="{{ $person['position'] }}">
                                        <span class="room-board-rank">{{ $person['rank'] }}</span>
                                        <span class="room-board-person-name">{{ $person['name'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="panel">
            <div class="panel-header">
                <div>
                    <p class="panel-kicker">Grading</p>
                    <h2>{{ count($board['findings']) === 0 ? 'Nothing to answer for' : count($board['findings']).' '.str('finding')->plural(count($board['findings'])) }}</h2>
                </div>
            </div>
            <div class="room-board-body">
                @if(count($board['findings']) === 0)
                    <div class="room-board-clear"><x-icon name="check-circle" /> <span>Every room meets its standard, and nobody is standing where they should not be.</span></div>
                @else
                    <div class="room-board-findings">
                        @foreach($board['findings'] as $finding)
                            <div class="room-board-finding {{ $finding['level'] === 'blocker' ? 'is-blocker' : 'is-warning' }}">
                                <span class="room-board-finding-level">{{ $finding['level'] === 'blocker' ? 'Blocker' : 'Warning' }}</span>
                                <span>
                                    <span class="room-board-finding-message">{{ $finding['message'] }}</span>
                                    <span class="room-board-finding-detail">{{ $finding['detail'] }}</span>
                                    <span class="room-board-finding-code">{{ $finding['code'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        @if($canManage)
            @include('schedules.partials.room-assign-modal', ['date' => $date])
        @endif
    @endif
@endsection
