document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarCloseButtons = document.querySelectorAll('[data-sidebar-close]');

    const closeSidebar = () => {
        body.classList.remove('sidebar-open');
        sidebarToggle?.setAttribute('aria-expanded', 'false');
    };

    sidebarToggle?.addEventListener('click', () => {
        const willOpen = !body.classList.contains('sidebar-open');

        body.classList.toggle('sidebar-open', willOpen);
        sidebarToggle.setAttribute('aria-expanded', String(willOpen));
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
});
