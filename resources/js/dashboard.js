import { Dropdown, Tooltip } from 'bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const root = document.documentElement;
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarCloseButtons = document.querySelectorAll('[data-sidebar-close]');
    const sidebarCollapseButton = document.querySelector('[data-sidebar-collapse]');
    const desktopSidebar = window.matchMedia('(min-width: 1101px)');
    const sidebarStorageKey = 'workforce.sidebar';
    const topbarClock = document.querySelector('[data-topbar-clock]');

    if (topbarClock) {
        const dateOutput = topbarClock.querySelector('[data-topbar-date]');
        const timeOutput = topbarClock.querySelector('[data-topbar-time]');
        const timezone = topbarClock.dataset.timezone || 'Asia/Manila';
        const serverEpoch = Number(topbarClock.dataset.serverEpoch) * 1000;
        const baselineEpoch = Number.isFinite(serverEpoch) ? serverEpoch : Date.now();
        const baselineLocalTime = Date.now();

        try {
            const dateFormatter = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            });
            const timeFormatter = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
            });

            const renderClock = () => {
                const currentTime = new Date(baselineEpoch + (Date.now() - baselineLocalTime));

                dateOutput.textContent = dateFormatter.format(currentTime);
                timeOutput.textContent = timeFormatter.format(currentTime);
                topbarClock.dateTime = currentTime.toISOString();
            };

            renderClock();
            window.setInterval(renderClock, 1000);
        } catch (error) {
            // Keep the server-rendered date and time if Intl rejects a timezone.
        }
    }

    document.querySelectorAll('[data-dashboard-action-menu]').forEach((toggle) => {
        Dropdown.getOrCreateInstance(toggle, {
            popperConfig: (defaultConfig) => ({
                ...defaultConfig,
                strategy: 'fixed',
            }),
        });
    });

    const syncCollapseButton = () => {
        if (!sidebarCollapseButton) {
            return;
        }

        const isCollapsed = root.dataset.sidebar === 'collapsed';
        const action = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';

        sidebarCollapseButton.setAttribute('aria-expanded', String(!isCollapsed));
        sidebarCollapseButton.setAttribute('aria-label', action);
        sidebarCollapseButton.dataset.sidebarLabel = action;
    };

    /* Collapsed, a nav item is a bare icon and the word it stands for is gone
       from the screen, so hovering one has to give the word back.

       It cannot be drawn in CSS on the link itself: the nav scrolls, so it
       carries overflow-x: hidden, and anything painted past the rail's edge is
       clipped off at it. Bootstrap renders into <body>, clear of that clip —
       and it takes each link's title attribute with it, which is what stops the
       browser's own slow tooltip from doubling up underneath. */
    const sidebarTooltips = Array.from(
        document.querySelectorAll('.sidebar-link[title]'),
        (link) => Tooltip.getOrCreateInstance(link, {
            placement: 'right',
            container: 'body',
            customClass: 'sidebar-tooltip',
            // The arrow is styled away, so the bubble buys its own gap here.
            offset: [0, 10],
            // Long enough that a pointer crossing the rail on its way somewhere
            // else does not trail bubbles behind it; short enough to answer a
            // hover that meant to ask.
            delay: { show: 320, hide: 60 },
        }),
    );

    /* Expanded, the label is already on screen in full, and a bubble repeating
       it is noise — so the instances are built once and switched with the rail
       rather than created and torn down. Below the desktop breakpoint the rail
       is a drawer that always shows its labels, so they stay off there too. */
    const syncSidebarTooltips = () => {
        const isIconOnly = desktopSidebar.matches && root.dataset.sidebar === 'collapsed';

        sidebarTooltips.forEach((tooltip) => {
            if (isIconOnly) {
                tooltip.enable();

                return;
            }

            // hide() before disable(): disabling alone strands one that is
            // already open, which is exactly what happens when the rail is
            // expanded from under the pointer.
            tooltip.hide();
            tooltip.disable();
        });
    };

    desktopSidebar.addEventListener('change', syncSidebarTooltips);

    const setDesktopSidebar = (isCollapsed, persist = false) => {
        root.dataset.sidebar = isCollapsed ? 'collapsed' : 'expanded';
        syncCollapseButton();
        syncSidebarTooltips();

        if (persist) {
            try {
                localStorage.setItem(sidebarStorageKey, root.dataset.sidebar);
            } catch (error) {
                // The sidebar still works when browser storage is unavailable.
            }
        }
    };

    const closeSidebar = () => {
        body.classList.remove('sidebar-open');
        sidebarToggle?.setAttribute('aria-expanded', 'false');
    };

    sidebarToggle?.addEventListener('click', () => {
        const willOpen = !body.classList.contains('sidebar-open');

        body.classList.toggle('sidebar-open', willOpen);
        sidebarToggle.setAttribute('aria-expanded', String(willOpen));
    });

    sidebarCollapseButton?.addEventListener('click', () => {
        if (!desktopSidebar.matches) {
            return;
        }

        setDesktopSidebar(root.dataset.sidebar !== 'collapsed', true);
    });

    sidebarCloseButtons.forEach((button) => {
        button.addEventListener('click', closeSidebar);
    });

    document.querySelectorAll('.sidebar-link').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 1100) {
                closeSidebar();
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            const searchInput = document.querySelector('.global-search input');

            if (searchInput) {
                event.preventDefault();
                searchInput.focus();
            }
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1100) {
            closeSidebar();
        }
    });

    // Attendance overview hover layer. The tooltip only ever enhances: every value
    // it shows is also in the panel's data table, so nothing is gated behind hover.
    const attendanceChart = document.querySelector('[data-attendance-chart]');

    if (attendanceChart) {
        const series = [
            { key: 'present', fill: 'present', label: 'Present' },
            { key: 'late', fill: 'late', label: 'Late' },
            { key: 'onLeave', fill: 'on_leave', label: 'On leave' },
            { key: 'absent', fill: 'absent', label: 'Absent' },
        ];

        const tooltip = document.createElement('div');
        tooltip.className = 'attendance-tooltip';
        tooltip.setAttribute('aria-hidden', 'true');

        const dayLabel = document.createElement('span');
        dayLabel.className = 'attendance-tooltip-day';
        tooltip.append(dayLabel);

        const valueCells = series.map((entry) => {
            const row = document.createElement('div');
            row.className = 'attendance-tooltip-row';

            const key = document.createElement('span');
            key.className = `attendance-tooltip-key attendance-fill-${entry.fill}`;

            const label = document.createElement('span');
            label.textContent = entry.label;

            const value = document.createElement('b');

            row.append(key, label, value);
            tooltip.append(row);

            return value;
        });

        attendanceChart.append(tooltip);

        const clearActive = () => attendanceChart
            .querySelectorAll('.attendance-column.is-active')
            .forEach((column) => column.classList.remove('is-active'));

        const hideTooltip = () => {
            tooltip.dataset.visible = 'false';
            clearActive();
        };

        const showTooltip = (column) => {
            clearActive();

            // Every label here is server data, so it goes in as text, never markup.
            dayLabel.textContent = column.dataset.day ?? '';
            series.forEach((entry, index) => {
                valueCells[index].textContent = column.dataset[entry.key] ?? '0';
            });

            const host = attendanceChart.getBoundingClientRect();
            const anchor = (column.querySelector('.attendance-stack') ?? column).getBoundingClientRect();
            const halfWidth = tooltip.offsetWidth / 2;
            const centre = anchor.left - host.left + anchor.width / 2;

            // The readout sits beside its column, level with the bar tip, so it never
            // covers the bar it is describing however tall that bar happens to be.
            const offset = anchor.width / 2 + halfWidth + 12;
            const fitsRight = centre + offset + halfWidth <= host.width;
            const left = centre + (fitsRight ? offset : -offset);
            const top = anchor.top - host.top;

            tooltip.style.left = `${Math.min(Math.max(left, halfWidth + 4), Math.max(host.width - halfWidth - 4, halfWidth + 4))}px`;
            tooltip.style.top = `${Math.min(Math.max(top, 4), Math.max(host.height - tooltip.offsetHeight - 4, 4))}px`;
            tooltip.dataset.visible = 'true';
            column.classList.add('is-active');
        };

        attendanceChart.querySelectorAll('[data-attendance-column]').forEach((column) => {
            column.addEventListener('pointerenter', () => showTooltip(column));
            column.addEventListener('focus', () => showTooltip(column));
            column.addEventListener('pointerleave', hideTooltip);
            column.addEventListener('blur', hideTooltip);
        });

        attendanceChart.querySelector('.attendance-plot-scroll')?.addEventListener('scroll', hideTooltip);
    }

    syncCollapseButton();
    syncSidebarTooltips();
});
