/**
 * "On the clock for 4h 12m", wherever that appears.
 *
 * Two pages want it now — the staff dashboard's card and the attendance page's
 * phone fold — and a second copy of a clock is how two clocks end up disagreeing
 * by a minute on the same screen. One implementation, driven entirely by data
 * attributes, so a third caller needs no JavaScript at all.
 *
 *   [data-elapsed-since]  the readout. Its value is the check-in as a Unix
 *                         timestamp; when empty, the nearest ancestor carrying
 *                         data-clocked-in-since supplies it instead.
 *   [data-elapsed-bar]    optional. Filled to the fraction of
 *                         data-elapsed-total (seconds) that has passed.
 *
 * Everything here restates a time the page has already rendered in words, so a
 * browser that never runs it still shows a correct, if static, card.
 */

const HALF_MINUTE = 30_000;

const sinceFor = (output) => {
    const own = Number(output.dataset.elapsedSince);

    if (Number.isFinite(own) && own > 0) {
        return own * 1000;
    }

    const card = output.closest('[data-clocked-in-since]');
    const inherited = Number(card?.dataset.clockedInSince);

    return Number.isFinite(inherited) && inherited > 0 ? inherited * 1000 : null;
};

const format = (seconds) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return `${hours}h ${String(minutes).padStart(2, '0')}m`;
};

const track = (output) => {
    const since = sinceFor(output);

    if (since === null) {
        return;
    }

    // Scoped to the readout's own card so a page with two of these cannot have
    // one bar following the other's clock.
    const bar = output.closest('[data-elapsed-scope]')?.querySelector('[data-elapsed-bar]')
        ?? document.querySelector('[data-elapsed-bar]');
    const total = Number(bar?.dataset.elapsedTotal);

    const render = () => {
        // Clamped at zero: a terminal whose clock runs a little ahead of the
        // browser's must not render the shift as negative.
        const seconds = Math.max(0, Math.floor((Date.now() - since) / 1000));

        output.textContent = format(seconds);

        if (bar && Number.isFinite(total) && total > 0) {
            // Clamped at 100 as well — somebody who stays past the end of their
            // shift is over, not broken, and the bar says full rather than
            // running off the end of the card.
            const percent = Math.min(100, Math.round((seconds / total) * 100));

            bar.style.width = `${percent}%`;
            bar.parentElement?.setAttribute('aria-valuenow', String(percent));
        }
    };

    render();
    // Half a minute: the readout is written to the minute, so anything finer
    // just spends battery on a number that has not changed.
    window.setInterval(render, HALF_MINUTE);
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-elapsed-since]').forEach(track);
});
