/*
 * The room board's two pieces of interaction: picking who goes into a cell,
 * and taking somebody out of one with an Undo.
 *
 * The board itself is drawn by the server and every change is an ordinary form
 * post, so the page works with this file blocked — the "+ Add" buttons are
 * links to the same screen with the cell pre-opened. The picker fetches the
 * candidate list for one cell so it can say why somebody is unavailable
 * instead of silently leaving them out.
 */

import { toast } from './toast';

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

/*
 * Taking somebody out of a room: done at once, with Undo in the shared toast
 * rather than a prompt first. A charge nurse moves people many times a shift,
 * and a question before every correct removal costs more than an Undo after
 * the occasional wrong one.
 *
 * The form stays a plain DELETE post underneath, so without this script (or
 * when anything here fails) the page falls back to exactly that, and the
 * server's own banner reports the outcome.
 */
const board = document.querySelector('[data-room-board]');

if (board) {
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // A real navigation, so the "Leave site?" guard must not ask about it.
    const navigateFor = (fn) => {
        window.markIntentionalNavigation?.();
        fn();
    };

    // Redraw the board from the server rather than patching counts and cell
    // states by hand: whether a cell is short, blocked or fine is the server's
    // call. The assign modal stays as it is, since room-board.js holds on to it.
    const redraw = async () => {
        const response = await fetch(window.location.href, {
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(String(response.status));

        const fresh = new DOMParser()
            .parseFromString(await response.text(), 'text/html')
            .querySelector('[data-room-board]');
        if (!fresh) throw new Error('No board in the response.');

        fresh.querySelector('#roomAssignModal')?.remove();
        const modal = board.querySelector('#roomAssignModal');
        board.replaceChildren(...fresh.childNodes, ...(modal ? [modal] : []));
    };

    // Where the keyboard goes once the chip it was on has gone: the same
    // cell's "+ Add", found again after the redraw.
    const cellKey = (form) => {
        const add = form.closest('.room-board-cell')?.querySelector('.room-board-add');

        return add ? { room: add.dataset.roomId, shift: add.dataset.shiftId } : null;
    };

    const focusCell = (key) => {
        if (!key) return;
        board.querySelector(`.room-board-add[data-room-id="${key.room}"][data-shift-id="${key.shift}"]`)
            ?.focus({ preventScroll: true });
    };

    const postJson = (url, init) => fetch(url, {
        ...init,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            ...(init.body ? { 'Content-Type': 'application/json' } : {}),
        },
    });

    // Undo that the server refused (the slot filled up, the ward was locked
    // meanwhile) is resent as an ordinary post, so the reason arrives as the
    // page's error banner — errors never go in a toast.
    const undoAsForm = (undo) => navigateFor(() => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = board.dataset.roomAssignUrl;
        form.hidden = true;
        Object.entries({ _token: csrfToken(), ...undo }).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.append(input);
        });
        document.body.append(form);
        form.submit();
    });

    const reassign = async (undo, key) => {
        try {
            const response = await postJson(board.dataset.roomAssignUrl, {
                method: 'POST',
                body: JSON.stringify(undo),
            });
            if (!response.ok) {
                undoAsForm(undo);
                return;
            }
            const { message } = await response.json();
            await redraw();
            focusCell(key);
            toast(message);
        } catch {
            undoAsForm(undo);
        }
    };

    board.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-room-remove]');
        if (!form) return;

        event.preventDefault();
        const button = form.querySelector('button');
        if (button) button.disabled = true;
        const hadFocus = form.contains(document.activeElement);
        const key = cellKey(form);

        let payload;
        try {
            const response = await postJson(form.action, { method: 'DELETE' });
            if (!response.ok) throw new Error(String(response.status));
            payload = await response.json();
        } catch {
            // Nothing has changed that we know of: let the plain post run and
            // report whatever the server says.
            navigateFor(() => form.submit());
            return;
        }

        try {
            await redraw();
        } catch {
            // The removal happened; only the redraw failed.
            navigateFor(() => window.location.reload());
            return;
        }

        if (hadFocus) focusCell(key);

        toast(payload.message, payload.undo ? {
            action: 'Undo',
            onAction: () => reassign(payload.undo, key),
        } : {});
    });
}
