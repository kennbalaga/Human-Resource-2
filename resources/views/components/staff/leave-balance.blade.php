@props(['leave'])

<section class="panel staff-leave" id="leave-balance" aria-labelledby="leave-balance-title">
    <div class="panel-header">
        <div>
            <p class="panel-kicker">Leave</p>
            <h2 id="leave-balance-title">Leave balance</h2>
        </div>
        <span class="staff-panel-meta">{{ $leave['year'] }} credits</span>
    </div>

    <div class="staff-leave-body">
        <table class="staff-leave-table">
            <caption class="visually-hidden">My leave credits for {{ $leave['year'] }}</caption>
            <thead>
                <tr>
                    <th scope="col">Leave type</th>
                    <th scope="col">Available</th>
                    <th scope="col">Used</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($leave['balances'] as $balance)
                    <tr>
                        <th scope="row">{{ $balance['name'] }}</th>
                        <td><b>{{ rtrim(rtrim(number_format($balance['available'], 1), '0'), '.') }}</b> {{ Str::plural('day', $balance['available']) }}</td>
                        <td>{{ rtrim(rtrim(number_format($balance['used'], 1), '0'), '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <a href="{{ route('leaves.index') }}" class="btn btn-primary staff-primary-action">
            <x-icon name="leave" /> <span>Request leave</span>
        </a>

        <div class="staff-leave-tracker">
            <p class="staff-tracker-head">Recent requests</p>
            @forelse ($leave['recent'] as $request)
                <p class="staff-tracker-row">
                    <span class="staff-chip staff-chip-{{ $request['tone'] }}">{{ $request['status_label'] }}</span>
                    <span class="staff-tracker-copy">
                        <strong>{{ $request['type'] }}</strong>
                        <small>{{ $request['range'] }}, {{ $request['year'] }}</small>
                    </span>
                </p>
            @empty
                <p class="staff-tracker-empty">You have not filed any leave requests yet.</p>
            @endforelse
        </div>
    </div>
</section>
