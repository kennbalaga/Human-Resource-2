@props(['changes'])

{{-- A quiet week has nothing to warn about, and an empty red band trains people to
     ignore the band. The section only exists when something actually moved. --}}
@if (! empty($changes))
    <section class="staff-alert" id="schedule-changes" role="region" aria-labelledby="schedule-changes-title">
        <div class="staff-alert-header">
            <span class="staff-alert-icon"><x-icon name="alert" /></span>
            <div>
                <p class="staff-alert-kicker">Action needed</p>
                <h2 id="schedule-changes-title">
                    {{ count($changes) }} schedule {{ Str::plural('change', count($changes)) }} affecting you
                </h2>
            </div>
        </div>

        <ul class="staff-change-list">
            @foreach ($changes as $change)
                <li class="staff-change staff-change-{{ $change['type'] }}">
                    <div class="staff-change-date">
                        <strong>{{ $change['date_label'] }}</strong>
                        <span>{{ $change['type_label'] }}</span>
                    </div>

                    <div class="staff-change-swap">
                        <span class="staff-change-before">
                            <small>Previous</small>
                            <b>{{ $change['from'] }}</b>
                        </span>
                        <x-icon name="chevron-right" class="staff-change-arrow" />
                        <span class="staff-change-after">
                            <small>New</small>
                            <b>{{ $change['to'] }}</b>
                        </span>
                    </div>

                    <div class="staff-change-reason">
                        <small>Reason</small>
                        <b>{{ $change['reason'] }}</b>
                        @if ($change['announced'])
                            <span>Updated {{ $change['announced'] }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <a href="{{ route('schedules.index') }}" class="staff-alert-link">Review my full schedule <x-icon name="chevron-right" /></a>
    </section>
@endif
