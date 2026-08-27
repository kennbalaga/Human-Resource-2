const initializePositionFiltering = () => {
    document.querySelectorAll('[data-employee-assignment]').forEach((form) => {
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
    const fullPageLink = panel.querySelector('[data-employee-panel-full]');
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
        if (fullPageLink) fullPageLink.href = url;
        window.bootstrap?.Offcanvas.getOrCreateInstance(panel).show();

        load(url).catch(() => {
            window.location.href = url;
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
};

document.addEventListener('DOMContentLoaded', () => {
    initializePositionFiltering();
    initializeLiveOrganizationFilters();
    initializeLiveEmployeeDirectory();
    reopenModalsWithErrors();
    initializeEmployeePanel();
});
