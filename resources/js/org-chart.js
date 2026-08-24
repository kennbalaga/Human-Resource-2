/**
 * Collapse/expand for the reporting-line chart.
 *
 * The tree is fully rendered server-side, so this only ever toggles
 * visibility — no fetching, and the chart stays readable with JS disabled.
 */
document.addEventListener('DOMContentLoaded', () => {
    const chart = document.querySelector('[data-org-chart]');
    if (!chart) return;

    const setCollapsed = (branch, collapsed) => {
        const toggle = branch.querySelector(':scope > .org-card > [data-org-toggle]');
        const children = branch.querySelector(':scope > .org-children');
        if (!toggle || !children) return;

        branch.classList.toggle('is-collapsed', collapsed);
        children.hidden = collapsed;
        toggle.setAttribute('aria-expanded', String(!collapsed));

        const name = branch.querySelector(':scope > .org-card .org-name')?.textContent?.trim() ?? 'this employee';
        toggle.setAttribute(
            'aria-label',
            `${collapsed ? 'Expand' : 'Collapse'} direct reports of ${name}`,
        );
    };

    chart.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-org-toggle]');
        if (toggle) {
            const branch = toggle.closest('[data-org-branch]');
            if (branch) setCollapsed(branch, !branch.classList.contains('is-collapsed'));
            return;
        }

        if (event.target.closest('[data-org-expand-all]')) {
            chart.querySelectorAll('[data-org-branch]').forEach((branch) => setCollapsed(branch, false));
            return;
        }

        if (event.target.closest('[data-org-collapse-all]')) {
            // The roots stay open — collapsing them too would leave the panel
            // looking empty, which reads as a broken page rather than a
            // collapsed one.
            chart
                .querySelectorAll('.org-children [data-org-branch]')
                .forEach((branch) => setCollapsed(branch, true));
        }
    });
});
