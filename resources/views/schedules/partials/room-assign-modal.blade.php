{{-- One picker serves every cell: the board is drawn by the server and the
     candidate list is fetched for whichever cell was opened. --}}
<div class="modal fade" id="roomAssignModal" tabindex="-1" aria-labelledby="roomAssignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content schedule-modal-content">
            <form method="POST" action="{{ route('schedules.rooms.store') }}">
                @csrf
                <input type="hidden" name="room_id" value="">
                <input type="hidden" name="shift_id" value="">
                <input type="hidden" name="employee_id" value="">
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">

                <div class="modal-header">
                    <div>
                        <p class="panel-kicker">Place a rostered shift</p>
                        <h2 class="modal-title" id="roomAssignModalLabel" data-room-assign-title>Assign staff</h2>
                        <p class="room-board-candidate-role" data-room-assign-subtitle></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body" data-room-assign-list></div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" data-room-assign-submit disabled>Put in this room</button>
                </div>
            </form>
        </div>
    </div>
</div>
