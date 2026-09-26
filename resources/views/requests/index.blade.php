{{--
    Everything asked for, in one list.

    Read-only by design: each row links back to the page that owns its kind,
    where the form, the validation and the permissions already live. What this
    page adds is the answer to "where has my request got to", which none of the
    three owning pages can give on its own.
--}}
@extends('layouts.app')

@section('title', 'Requests')

@section('content')
    <div class="requests-screen">
        <section class="page-heading">
            <div>
                <p class="eyebrow">Time off &amp; scheduling</p>
                <h1>Requests</h1>
                <p>Leave, shift swaps and preferred days off — everything you have asked for and what it is waiting on.</p>
            </div>
        </section>

        <section class="panel requests-summary" aria-label="Your balances">
            @foreach ($balances as $balance)
                <div>
                    <p class="requests-summary-value">{{ rtrim(rtrim(number_format($balance->available_days, 1), '0'), '.') }}</p>
                    <p class="requests-summary-label">{{ $balance->leaveType->name }}</p>
                </div>
            @endforeach
            <div>
                <p class="requests-summary-value {{ $awaiting > 0 ? 'is-warning' : '' }}">{{ $awaiting }}</p>
                <p class="requests-summary-label">Awaiting a reply</p>
            </div>
        </section>

        <nav class="requests-filters" aria-label="Filter requests">
            @foreach ([
                'all' => 'All',
                'leave' => 'Leave',
                'swap' => 'Swaps',
                'day-off' => 'Days off',
            ] as $key => $label)
                {{-- A filter that would show nothing is still offered rather
                     than hidden: its zero is the answer to "have I asked for
                     any of these?". --}}
                <a
                    href="{{ route('requests.index', $key === 'all' ? [] : ['type' => $key]) }}"
                    @class(['is-active' => $filter === $key])
                    @if ($filter === $key) aria-current="page" @endif
                >{{ $label }} <span>{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        @forelse ($items as $item)
            <a class="panel requests-item" href="{{ $item['url'] }}">
                <div class="requests-item-head">
                    <span class="requests-item-kind">{{ $item['kind_label'] }}</span>
                    <span class="status-badge status-{{ $item['tone'] }}"><span class="status-dot"></span>{{ $item['status_label'] }}</span>
                </div>
                <strong class="requests-item-title">{{ $item['title'] }}</strong>
                @if ($item['detail'])
                    <span class="requests-item-detail">{{ $item['detail'] }}</span>
                @endif
            </a>
        @empty
            <div class="panel requests-empty">
                <x-icon name="inbox" />
                <strong>Nothing asked for yet</strong>
                <span>
                    @if ($filter === 'all')
                        Leave, a shift swap or a preferred day off will appear here once you file one.
                    @else
                        Nothing of this kind. Try All.
                    @endif
                </span>
            </div>
        @endforelse

        {{-- The one action on this page, in the thumb's half of the screen.
             Each destination is the page that owns that kind of request. --}}
        <details class="requests-new">
            <summary>
                <x-icon name="plus" /> New request
            </summary>
            <div class="requests-new-menu">
                <a href="{{ route('leaves.index') }}"><x-icon name="leave" /> Leave</a>
                @if ($canSwap)
                    <a href="{{ route('schedule-preferences.index') }}#shift-swaps"><x-icon name="swap" /> Shift swap</a>
                @endif
                <a href="{{ route('schedule-preferences.index') }}"><x-icon name="calendar" /> Preferred day off</a>
            </div>
        </details>
    </div>
@endsection
