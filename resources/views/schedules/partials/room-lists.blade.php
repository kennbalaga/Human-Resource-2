{{-- Theatre lists: a room held by the clock rather than by the shift. Only the
     rooms that work that way carry one, so a ward panel does not appear on a
     board that has no theatres in it. --}}
{{-- The caller works the same set out before it lays the page out, since
     whether this panel renders decides whether it shares its row. It is passed
     in rather than recomputed, so the two can never disagree. --}}
@php($theatreRows = $theatreRows ?? collect($board['rooms'])->filter(fn ($row) => in_array($row['room']->room_type, App\Models\Room::restrictedTypes(), true)))

@if($theatreRows->isNotEmpty())
    <section class="panel">
        <div class="panel-header">
            <div>
                <p class="panel-kicker">Theatre lists</p>
                <h2>{{ $board['summary']['bookings'] }} {{ str('case')->plural($board['summary']['bookings']) }} on {{ $date->format('j M') }}</h2>
            </div>
        </div>
        <div class="room-board-body">
            @foreach($theatreRows as $row)
                @php($room = $row['room'])
                <div class="room-board-unplaced-group">
                    <h3>{{ $room->code }} — {{ $room->name }}</h3>

                    @forelse($row['bookings'] as $booking)
                        <div class="room-board-finding {{ $booking['scrubbed'] === 0 ? 'is-warning' : '' }}">
                            <span class="room-board-finding-level">{{ $booking['window'] }}</span>
                            <span>
                                <span class="room-board-finding-message">{{ $booking['purpose'] }}</span>
                                <span class="room-board-finding-detail">
                                    {{ $booking['lead'] ? 'Led by '.$booking['lead'].' · ' : '' }}{{ $booking['scrubbed'] }} scrubbed · {{ App\Models\RoomBooking::statuses()[$booking['status']] ?? $booking['status'] }}
                                </span>
                                @if($canManage)
                                    <form method="POST" action="{{ route('schedules.room-bookings.destroy', $booking['id']) }}"
                                          data-confirm="Stand down {{ $booking['purpose'] }} in {{ $room->code }} ({{ $booking['window'] }})? The booking comes off the theatre list. This can’t be undone."
                                          data-confirm-button="Stand down case" data-confirm-tone="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button class="room-board-add" type="submit">Stand down</button>
                                    </form>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="room-board-candidate-role">No case booked in {{ $room->code }} today.</p>
                    @endforelse

                    @if($canManage && $room->isUsable())
                        <form class="room-board-booking-form" method="POST" action="{{ route('schedules.room-bookings.store', $room) }}">
                            @csrf
                            <input type="hidden" name="work_date" value="{{ $date->toDateString() }}">
                            <label><span>From</span><input type="time" name="start_time" required></label>
                            <label><span>To</span><input type="time" name="end_time" required></label>
                            <label class="room-board-booking-purpose"><span>Case</span><input type="text" name="purpose" maxlength="255" required placeholder="Appendectomy"></label>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Hold {{ $room->code }}</button>
                        </form>
                    @endif
                </div>
            @endforeach

            @error('start_time')<small class="organization-error">{{ $message }}</small>@enderror
            @error('end_time')<small class="organization-error">{{ $message }}</small>@enderror
            @error('purpose')<small class="organization-error">{{ $message }}</small>@enderror
        </div>
    </section>
@endif
