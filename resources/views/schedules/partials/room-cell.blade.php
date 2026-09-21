@if($cell['dark'])
    <div class="room-board-cell is-dark">
        <span>{{ $room->isUsable() ? 'Not running' : $room->status_label }}</span>
    </div>
@else
    <div class="room-board-cell is-{{ $cell['state'] }}">
        <div class="room-board-occupants">
            @foreach($cell['occupants'] as $person)
                <span @class([
                    'room-board-person',
                    'is-charge' => $person['is_charge'],
                    'is-cross-unit' => $person['cross_unit'],
                    'is-on-leave' => $person['on_leave'],
                ]) title="{{ $person['position'] }}{{ $person['cross_unit'] ? ' · borrowed from '.$person['department'] : '' }}">
                    <span class="room-board-rank">{{ $person['rank'] }}</span>
                    <span class="room-board-person-name">{{ $person['name'] }}</span>
                    @if($canManage)
                        <form method="POST" action="{{ route('schedules.rooms.destroy', $person['assignment_id']) }}">
                            @csrf
                            @method('DELETE')
                            <button class="room-board-remove" type="submit" aria-label="Take {{ $person['name'] }} out of {{ $room->code }}">&times;</button>
                        </form>
                    @endif
                </span>
            @endforeach
        </div>

        <div class="room-board-cell-footer">
            <span class="room-board-count" title="{{ $cell['requirement_source'] }}">{{ $cell['count'] }} / {{ $cell['required'] }}</span>
            @if($canManage)
                <button class="room-board-add" type="button"
                        data-bs-toggle="modal" data-bs-target="#roomAssignModal"
                        data-room-id="{{ $room->id }}"
                        data-room-code="{{ $room->code }}"
                        data-shift-id="{{ $shift->id }}"
                        data-shift-name="{{ $shift->name }}"
                        data-cell-summary="{{ $room->name }} · {{ $cell['count'] }} of {{ $cell['required'] }} needed{{ $cell['capacity'] ? ', holds '.$cell['capacity'] : '' }}"
                        data-candidates-url="{{ route('schedules.rooms.candidates', ['room' => $room->id, 'shift' => $shift->id, 'date' => $date->toDateString()]) }}">
                    + Add
                </button>
            @endif
        </div>
    </div>
@endif
