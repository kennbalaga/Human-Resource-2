/* `root` is the whole document on load, and the modal's content when a form has
   just been fetched into it - that form is not in the DOM when this first runs. */
const initializePositionFiltering = (root = document) => {
    root.querySelectorAll('[data-employee-assignment]').forEach((form) => {
        const department = form.querySelector('[data-department-select]');
        const position = form.querySelector('[data-position-select]');
        const generatedEmployeeNumber = form.querySelector('[data-generated-employee-number]');
        const hireDate = form.querySelector('[data-hire-date]');

        if (!department || !position) return;

        const filterPositions = (resetSelection = false) => {
            const departmentId = department.value;

            [...position.options].forEach((option) => {
                if (!option.value) return;

                const visible = !departmentId || option.dataset.departmentId === departmentId;
                option.hidden = !visible;
                option.disabled = !visible;
            });

            const selected = position.selectedOptions[0];
            if (resetSelection && selected?.disabled) position.value = '';
        };

        /* Mirrors EmployeeNumberGenerator: {POSITION CODE}-{HIRE YEAR}-{SEQUENCE}.
           The sequence is only known once the row is written, so the preview
           stops at the prefix the server is about to use. */
        const previewEmployeeNumber = () => {
            if (!generatedEmployeeNumber) return;

            const positionCode = position.selectedOptions[0]?.dataset.positionCode;
            const hireYear = hireDate?.value?.slice(0, 4) || new Date().getFullYear();
            generatedEmployeeNumber.value = positionCode
                ? `${positionCode}-${hireYear}-[next]`
                : 'Select a position first';
        };

        filterPositions();
        previewEmployeeNumber();
        department.addEventListener('change', () => {
            filterPositions(true);
            previewEmployeeNumber();
        });
        position.addEventListener('change', previewEmployeeNumber);
        hireDate?.addEventListener('change', previewEmployeeNumber);
    });
};

const initializeLiveOrganizationFilters = () => {
    document.querySelectorAll('form.organization-filters:not([data-live-filters])').forEach((form) => {
        const submit = () => (form.requestSubmit ? form.requestSubmit() : form.submit());

        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', submit);
        });

        /* A date filter behaves like a select, not like a search box: picking a
           day is a finished choice, so it submits at once rather than waiting
           out the debounce below. */
        form.querySelectorAll('input[type="date"]').forEach((field) => {
            field.addEventListener('change', submit);
        });

        const search = form.querySelector('input[type="search"]');
        if (!search) return;

        let debounceTimer;
        search.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(submit, 400);
        });
    });
};

/* Employee directory search/filter refresh the results panel via fetch
   instead of a full page navigation, so typing doesn't reload the page. */
const initializeLiveEmployeeDirectory = () => {
    const form = document.querySelector('#employee-filters-form');
    const results = document.querySelector('#employee-directory-results');
    if (!form || !results) return;

    const clearAction = form.querySelector('.organization-filter-actions');
    let activeRequest;

    const syncFormToUrl = (url) => {
        const params = new URL(url, window.location.origin).searchParams;
        const search = form.querySelector('input[type="search"]');
        if (search) search.value = params.get('search') || '';
        form.querySelectorAll('select').forEach((select) => {
            select.value = params.get(select.name) || '';
        });
        if (clearAction) clearAction.hidden = !(params.get('search') || params.get('department_id') || params.get('status'));
    };

    const load = async (url, { pushHistory = true } = {}) => {
        activeRequest?.abort();
        const controller = new AbortController();
        activeRequest = controller;

        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (!response.ok) return;

            results.innerHTML = await response.text();
            if (pushHistory) window.history.pushState({}, '', url);
        } catch (error) {
            if (error.name !== 'AbortError') throw error;
        } finally {
            results.removeAttribute('aria-busy');
        }
    };

    const submit = () => {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((key) => {
            if (!params.get(key)) params.delete(key);
        });
        const query = params.toString();
        load(`${form.action}${query ? `?${query}` : ''}`);
        if (clearAction) clearAction.hidden = query === '';
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submit();
    });

    form.querySelectorAll('select').forEach((select) => select.addEventListener('change', submit));

    const search = form.querySelector('input[type="search"]');
    let debounceTimer;
    search?.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(submit, 400);
    });

    clearAction?.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) return;
        event.preventDefault();
        form.reset();
        clearAction.hidden = true;
        load(link.href);
    });

    results.addEventListener('click', (event) => {
        const link = event.target.closest('.report-pagination a');
        if (!link) return;
        event.preventDefault();
        load(link.href);
        syncFormToUrl(link.href);
    });

    // The pager's "Page [ n ] of N" box refreshes in place like its links do.
    results.addEventListener('submit', (event) => {
        const jump = event.target.closest('[data-page-jump]');
        if (!jump) return;
        event.preventDefault();
        const url = new URL(jump.action, window.location.origin);
        url.hash = '';
        url.search = new URLSearchParams(new FormData(jump)).toString();
        load(url.toString());
        syncFormToUrl(url.toString());
    });

    window.addEventListener('popstate', () => {
        load(window.location.href, { pushHistory: false });
        syncFormToUrl(window.location.href);
    });
};

/* A failed create-employee submit comes back as a normal render of the
   directory, so the modal has to be reopened for the errors and the old input
   inside it to be seen at all. */
const reopenModalsWithErrors = () => {
    document.querySelectorAll('.modal[data-open-on-error]').forEach((element) => {
        window.bootstrap?.Modal.getOrCreateInstance(element).show();
    });
};

/* The directory's View button slides the employee record in over the list
   instead of navigating, so the filters, the page, and the scroll position all
   survive. The trigger stays a real link: modified clicks are left to the
   browser, and anything that goes wrong falls through to the profile page
   rather than leaving an empty drawer open.

   The same handler serves the "View details" links inside the panel, which is
   what lets a reader walk a reporting line without ever leaving the list. */
const initializeEmployeePanel = () => {
    const panel = document.getElementById('employeePanel');
    if (!panel) return;

    const body = panel.querySelector('[data-employee-panel-body]');
    if (!body) return;

    const loadingMarkup = body.innerHTML;
    const cache = new Map();
    /* Clicking through several employees quickly can land the responses out of
       order; only the newest request is allowed to paint. */
    let latestRequest = 0;

    const render = (markup) => {
        body.innerHTML = markup;
        body.removeAttribute('aria-busy');
        body.scrollTop = 0;

        /* The record carries the tab strip's overflow menu. Bootstrap's data-api
           would build it on first click with Popper's default absolute strategy,
           which the strip clips the moment it is narrow enough to scroll
           sideways; fixed positioning lifts the menu out of that box. The
           dashboard instantiates its own menus the same way, but it does so at
           load — this markup arrives long after. */
        body.querySelectorAll('[data-dashboard-action-menu]').forEach((toggle) => {
            window.bootstrap?.Dropdown.getOrCreateInstance(toggle, {
                popperConfig: (defaultConfig) => ({ ...defaultConfig, strategy: 'fixed' }),
            });
        });
    };

    const load = async (url) => {
        const request = (latestRequest += 1);

        if (cache.has(url)) {
            render(cache.get(url));
            return;
        }

        body.setAttribute('aria-busy', 'true');
        body.innerHTML = loadingMarkup;

        const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}panel=1`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(`Employee panel responded ${response.status}`);

        const markup = await response.text();
        cache.set(url, markup);

        if (request === latestRequest) render(markup);
    };

    const open = (url) => {
        window.bootstrap?.Offcanvas.getOrCreateInstance(panel).show();

        /* Navigating to the record's own URL used to be the fallback here. It
           cannot be any more: that URL now redirects back to this page with the
           panel set to open, so a fetch that keeps failing would bounce between
           the two forever. The failure is reported where the record would be. */
        load(url).catch(() => {
            body.removeAttribute('aria-busy');
            body.innerHTML = '<p class="employee-panel-status">This employee profile could not be loaded. Close the panel and try again.</p>';
        });
    };

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const trigger = event.target.closest?.('[data-employee-panel]');
        if (!trigger) return;

        event.preventDefault();
        open(trigger.href);
    });

    /* Arriving from a copied link, a global search result, or straight after a
       save: the server sent us here with the record to show. */
    const initial = panel.dataset.employeePanelInitial;
    if (initial) open(initial);
};

/* Edit opens over the directory too, so a correction never costs the reader
   their filters or their place in the list. One shell serves every row: the
   form is fetched for whichever employee was clicked.

   The trigger stays a real link to the full edit page, so a middle-click, a
   copied link, or a browser without our scripts all still get a working form. */
const initializeEmployeeEditModal = () => {
    const modal = document.getElementById('editEmployeeModal');
    if (!modal) return;

    const content = modal.querySelector('[data-employee-edit-body]');
    if (!content) return;

    const loadingMarkup = modal.querySelector('[data-employee-edit-loading]')?.innerHTML ?? '';
    /* Clicking Edit on several rows in quick succession can land the responses
       out of order; only the newest request is allowed to paint. */
    let latestRequest = 0;

    const load = async (url) => {
        const request = (latestRequest += 1);
        content.innerHTML = loadingMarkup;

        const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}modal=1`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(`Employee edit form responded ${response.status}`);

        const markup = await response.text();
        if (request !== latestRequest) return;

        content.innerHTML = markup;
        /* The department/position pairing is wired at load time, and this form
           did not exist then. Nothing else on the page is touched. */
        initializePositionFiltering(content);
    };

    const show = (url) => {
        window.bootstrap?.Modal.getOrCreateInstance(modal).show();

        load(url).catch(() => {
            window.markIntentionalNavigation();
            window.location.href = url;
        });
    };

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const trigger = event.target.closest?.('[data-employee-edit]');
        if (!trigger) return;

        event.preventDefault();

        /* Edit is also offered from inside the open profile panel. Bootstrap's
           scroll lock is not reference counted, so a panel still closing behind
           an open modal would hand the body's scrollbar back underneath it -
           the modal waits for the panel to finish leaving. */
        const openPanel = document.querySelector('.offcanvas.show');
        if (!openPanel) {
            show(trigger.href);
            return;
        }

        openPanel.addEventListener('hidden.bs.offcanvas', () => show(trigger.href), { once: true });
        window.bootstrap?.Offcanvas.getOrCreateInstance(openPanel).hide();
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initializePositionFiltering();
    initializeLiveOrganizationFilters();
    initializeLiveEmployeeDirectory();
    reopenModalsWithErrors();
    initializeEmployeePanel();
    initializeEmployeeEditModal();
});
