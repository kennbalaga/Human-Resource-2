/*
 * The room board's one piece of interaction: picking who goes into a cell.
 *
 * The board itself is drawn by the server and every change is an ordinary form
 * post, so the page works with this file blocked — the "+ Add" buttons are
 * links to the same screen with the cell pre-opened. All this does is fetch the
 * candidate list for one cell so the picker can say why somebody is unavailable
 * instead of silently leaving them out.
 */

const dialog = document.getElementById('roomAssignModal');

if (dialog) {
    const title = dialog.querySelector('[data-room-assign-title]');
    const subtitle = dialog.querySelector('[data-room-assign-subtitle]');
    const list = dialog.querySelector('[data-room-assign-list]');
    const roomField = dialog.querySelector('input[name="room_id"]');
    const shiftField = dialog.querySelector('input[name="shift_id"]');
    const employeeField = dialog.querySelector('input[name="employee_id"]');
    const submit = dialog.querySelector('[data-room-assign-submit]');

    const setBusy = (message) => {
        list.innerHTML = '';
        const row = document.createElement('p');
        row.className = 'room-board-candidate-role';
        row.textContent = message;
        list.append(row);
    };

    const render = (candidates) => {
        list.innerHTML = '';

        if (candidates.length === 0) {
            setBusy('Nobody is rostered on this shift yet. Roster the shift first, then give it a room.');
            return;
        }

        candidates.forEach((candidate) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'room-board-candidate';
            button.disabled = Boolean(candidate.reason);

            const who = document.createElement('span');
            const name = document.createElement('span');
            name.className = 'room-board-candidate-name';
            name.textContent = candidate.name;
            const role = document.createElement('span');
            role.className = 'room-board-candidate-role';
            role.textContent = [candidate.position, `rank ${candidate.rank}`, candidate.cross_unit ? candidate.department : null]
                .filter(Boolean)
                .join(' · ');
            who.append(name, document.createElement('br'), role);

            const state = document.createElement('span');
            state.className = 'room-board-candidate-state';

            if (candidate.reason) {
                state.classList.add('is-blocked');
                state.textContent = candidate.reason;
            } else if (candidate.cross_unit) {
                state.classList.add('is-cross-unit');
                state.textContent = 'Borrowed unit';
            } else if (candidate.is_charge) {
                state.classList.add('is-charge');
                state.textContent = 'Charge cover';
            } else {
                state.textContent = 'Available';
            }

            button.append(who, state);

            button.addEventListener('click', () => {
                employeeField.value = candidate.employee_id;
                list.querySelectorAll('.room-board-candidate').forEach((other) => other.removeAttribute('aria-pressed'));
                button.setAttribute('aria-pressed', 'true');
                submit.disabled = false;
            });

            list.append(button);
        });
    };

    dialog.addEventListener('show.bs.modal', async (event) => {
        const trigger = event.relatedTarget;

        if (!trigger) {
            return;
        }

        roomField.value = trigger.dataset.roomId;
        shiftField.value = trigger.dataset.shiftId;
        employeeField.value = '';
        submit.disabled = true;
        title.textContent = `${trigger.dataset.roomCode} · ${trigger.dataset.shiftName}`;
        subtitle.textContent = trigger.dataset.cellSummary || '';
        setBusy('Reading today’s roster…');

        try {
            const response = await fetch(trigger.dataset.candidatesUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            render((await response.json()).candidates ?? []);
        } catch {
            setBusy('The roster for this shift could not be read. Reload the page and try again.');
        }
    });
}
