@props(['queue'])

@php
    /*
     * The way in, for the panel's footer: the first group that actually has
     * something waiting, so the link lands where the work is rather than on a
     * fixed module that may be empty.
     */
    $primaryGroup = collect($queue['groups'])->firstWhere('count', '>', 0) ?? ($queue['groups'][0] ?? null);
@endphp

{{-- Everything waiting on this reader's decision, above everything the page
     merely reports. One row per group: what it is, what has waited longest, and
     how many — the row itself is the way into the module that answers it. --}}
<section class="panel approval-queue" id="approval-queue" aria-labelledby="approval-queue-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Needs your decision</p>
            <h2 id="approval-queue-title">Pending approvals</h2>
        </div>
        @if ($queue['total'] > 0)
            <span @class(['approval-queue-total', 'is-overdue' => ($queue['oldest_days'] ?? 0) >= 3])>
                <x-icon name="clock" />
                {{ number_format($queue['total']) }} waiting{{ $queue['oldest_label'] ? ' · oldest '.$queue['oldest_label'] : '' }}
            </span>
        @endif
    </div>

    @if ($queue['total'] < 1)
        <div class="compact-empty-state approval-queue-empty">
            <x-icon name="check-circle" />
            <p>Nothing is waiting on you. Leave requests, submitted timesheets and attendance days awaiting approval appear here the moment they arrive.</p>
        </div>
    @else
        {{-- The ordering claim, stated once. Each row then names its own oldest
             item, so the promise is visible in the rows as well as above them. --}}
        <p class="approval-queue-caption">Oldest first — a queue sorted by arrival buries what has been waiting longest.</p>

        <div class="approval-queue-groups">
            @foreach ($queue['groups'] as $group)
                @php
                    $oldest = $group['items'][0] ?? null;
                    $isStale = (bool) ($oldest['stale'] ?? false);
                @endphp
                <a @class(['approval-group', 'is-clear' => $group['count'] < 1]) href="{{ $group['url'] }}" aria-label="{{ $group['action_label'] }}">
                    <span class="approval-group-icon"><x-icon :name="$group['icon']" /></span>
                    <span class="approval-group-copy">
                        <strong>{{ $group['label'] }}</strong>
                        <span>
                            @if ($oldest)
                                {{ 'Oldest: '.$oldest['name'].', filed '.($oldest['waiting_label'] === 'today' ? 'today' : $oldest['waiting_label'].' ago') }}
                            @else
                                {{ $group['empty_label'] }}
                            @endif
                        </span>
                    </span>
                    @if ($group['count'] > 0)
                        <span class="status-badge {{ $isStale ? 'status-warning' : 'status-secondary' }}">
                            <span class="status-dot"></span>{{ $isStale ? 'Overdue' : 'On track' }}
                        </span>
                    @endif
                    <span class="approval-group-count">{{ number_format($group['count']) }}</span>
                </a>
            @endforeach
        </div>

        @if ($primaryGroup)
            <a class="panel-footer-link" href="{{ $primaryGroup['url'] }}">
                Open the approval queue <x-icon name="chevron-right" />
            </a>
        @endif
    @endif
</section>
