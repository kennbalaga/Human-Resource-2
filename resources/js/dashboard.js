import { Dropdown } from 'bootstrap';

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

    const setDesktopSidebar = (isCollapsed, persist = false) => {
        root.dataset.sidebar = isCollapsed ? 'collapsed' : 'expanded';
        syncCollapseButton();

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

    syncCollapseButton();
});
