/**
 * Staff / Nurse dashboard behaviour.
 *
 * Two enhancements, both optional by design. The elapsed counter restates a time
 * the card has already rendered, and the trend tooltip restates numbers the panel
 * already carries in its data table, so nothing here is the only way to read a
 * value on the page.
 */
document.addEventListener('DOMContentLoaded', () => {
    const elapsedOutput = document.querySelector('[data-elapsed-since]');
    const clockedInCard = document.querySelector('[data-clocked-in-since]');

    if (elapsedOutput && clockedInCard) {
        const since = Number(clockedInCard.dataset.clockedInSince) * 1000;

        if (Number.isFinite(since) && since > 0) {
            const renderElapsed = () => {
                // Clamp at zero: a terminal whose clock runs a little ahead of the
                // browser's must not render the shift as negative.
                const seconds = Math.max(0, Math.floor((Date.now() - since) / 1000));
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);

                elapsedOutput.textContent = `${hours}h ${String(minutes).padStart(2, '0')}m`;
            };

            renderElapsed();
            window.setInterval(renderElapsed, 30_000);
        }
    }

    const trend = document.querySelector('[data-staff-trend]');

    if (!trend) {
        return;
    }

    const rows = [
        { key: 'rate', label: 'Attendance rate' },
        { key: 'present', label: 'Present' },
        { key: 'late', label: 'Late' },
        { key: 'absent', label: 'Absent' },
    ];

    const tooltip = document.createElement('div');
    tooltip.className = 'staff-trend-tooltip';
    tooltip.setAttribute('aria-hidden', 'true');

    const monthLabel = document.createElement('span');
    monthLabel.className = 'staff-trend-tooltip-month';
    tooltip.append(monthLabel);

    const valueCells = rows.map((entry) => {
        const row = document.createElement('div');
        row.className = 'staff-trend-tooltip-row';

        const label = document.createElement('span');
        label.textContent = entry.label;

        const value = document.createElement('b');

        row.append(label, value);
        tooltip.append(row);

        return value;
    });

    trend.append(tooltip);

    const clearActive = () => trend
        .querySelectorAll('.staff-trend-column.is-active')
        .forEach((column) => column.classList.remove('is-active'));

    const hideTooltip = () => {
        tooltip.dataset.visible = 'false';
        clearActive();
    };

    const showTooltip = (column) => {
        clearActive();

        // Every label here is server data, so it goes in as text, never markup.
        monthLabel.textContent = column.dataset.month ?? '';
        rows.forEach((entry, index) => {
            valueCells[index].textContent = column.dataset[entry.key] ?? '0';
        });

        const host = trend.getBoundingClientRect();
        // Anchored to the column's own tip so the readout rides the bar it
        // describes, however tall that bar happens to be.
        const mark = column.querySelector('.staff-trend-fill') ?? column.querySelector('.staff-trend-void');
        const anchor = (mark ?? column).getBoundingClientRect();
        const halfWidth = tooltip.offsetWidth / 2;
        const centre = anchor.left - host.left + anchor.width / 2;
        const top = anchor.top - host.top - tooltip.offsetHeight - 8;

        tooltip.style.left = `${Math.min(Math.max(centre, halfWidth + 4), Math.max(host.width - halfWidth - 4, halfWidth + 4))}px`;
        tooltip.style.top = `${Math.max(top, 4)}px`;
        tooltip.dataset.visible = 'true';
        column.classList.add('is-active');
    };

    trend.querySelectorAll('.staff-trend-column').forEach((column) => {
        column.addEventListener('pointerenter', () => showTooltip(column));
        column.addEventListener('focus', () => showTooltip(column));
        column.addEventListener('pointerleave', hideTooltip);
        column.addEventListener('blur', hideTooltip);
    });
});
