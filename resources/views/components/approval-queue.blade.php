@props(['queue'])

{{-- Everything waiting on this reader's decision, above everything the page
     merely reports. Each group is answered in a different module, so each keeps
     its own count, its own oldest-first list, and its own way in. --}}
<section class="panel approval-queue" id="approval-queue" aria-labelledby="approval-queue-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Needs your decision</p>
            <h2 id="approval-queue-title">Pending approvals</h2>
        </div>
        @if ($queue['total'] > 0)
            <span @class(['approval-queue-total', 'is-overdue' => ($queue['oldest_days'] ?? 0) >= 3])>
                <strong>{{ number_format($queue['total']) }}</strong>
                <small>waiting</small>
            </span>
        @endif
    </div>

    @if ($queue['total'] < 1)
        <div class="compact-empty-state approval-queue-empty">
            <x-icon name="check-circle" />
            <p>Nothing is waiting on you. Leave requests, submitted timesheets and attendance days awaiting approval appear here the moment they arrive.</p>
        </div>
    @else
        <p class="approval-queue-caption">
            <span>Oldest first — a queue sorted by arrival buries what has been waiting longest.</span>
            @if ($queue['oldest_label'])
                <span class="approval-queue-oldest">Longest wait: {{ $queue['oldest_label'] }}</span>
            @endif
        </p>

        <div class="approval-queue-groups">
            @foreach ($queue['groups'] as $group)
                <article @class(['approval-group', 'is-clear' => $group['count'] < 1]) aria-labelledby="approval-group-{{ $group['key'] }}-title">
                    <header class="approval-group-header">
                        <span class="approval-group-icon"><x-icon :name="$group['icon']" /></span>
                        <div>
                            <h3 id="approval-group-{{ $group['key'] }}-title">{{ $group['label'] }}</h3>
                            {{-- Built as a string rather than inline directives: Blade
                                 does not treat `@if` as a directive when it is glued
                                 to the end of a word, and the phrase reads better
                                 assembled than split across three lines anyway. --}}
                            <p>
                                @if ($group['count'] > 0)
                                    {{ number_format($group['count']).' waiting'.($group['waiting_label'] ? ' · oldest '.$group['waiting_label'] : '') }}
                                @else
                                    {{ $group['empty_label'] }}
                                @endif
                            </p>
                        </div>
                        <span class="approval-group-count">{{ number_format($group['count']) }}</span>
                    </header>

                    @if (! empty($group['items']))
                        <ul class="approval-item-list">
                            @foreach ($group['items'] as $item)
                                <li>
                                    <a class="approval-item" href="{{ $item['url'] }}">
                                        <span class="approval-item-main">
                                            <strong>{{ $item['name'] }}</strong>
                                            <span class="approval-item-meta">
                                                {{ $item['summary'] }}
                                                @if ($item['department'])
                                                    · {{ $item['department'] }}
                                                @endif
                                            </span>
                                            <span class="approval-item-meta">{{ $item['detail'] }}</span>
                                        </span>
                                        {{-- The age is the whole reason this list is ordered
                                             the way it is, so it is shown per row and not
                                             only as a headline. --}}
                                        <span @class(['approval-item-age', 'is-stale' => $item['stale']])>
                                            @if ($item['stale'])
                                                <x-icon name="alert" />
                                            @endif
                                            {{ $item['waiting_label'] }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>

                        @if ($group['count'] > count($group['items']))
                            <a class="approval-group-more" href="{{ $group['url'] }}">
                                {{ number_format($group['count'] - count($group['items'])) }} more waiting <x-icon name="chevron-right" />
                            </a>
                        @endif
                    @endif

                    <a class="approval-group-link" href="{{ $group['url'] }}">
                        {{ $group['action_label'] }} <x-icon name="chevron-right" />
                    </a>
                </article>
            @endforeach
        </div>
    @endif
</section>
