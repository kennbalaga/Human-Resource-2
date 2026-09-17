/**
 * Workforce Analytics > Burnout Risk: live filters.
 *
 * Changing a filter refreshes the tiles, the department breakdown and the
 * employee list in place, the same way the employee directory does, instead
 * of reloading the page. The URL follows along, so a refresh, a bookmark or
 * the Back button lands on the same view. Anything that goes wrong falls back
 * to an ordinary navigation, so the page is never left half-updated.
 *
 * Without scripts the form still submits the normal way; the Update button is
 * only hidden once this is running.
 */
const initializeBurnoutFilters = () => {
    const form = document.querySelector('[data-burnout-filters]');
    const results = document.querySelector('[data-burnout-results]');
    if (!form || !results) return;

    const submitButton = form.querySelector('[data-burnout-filters-submit]');
    const clearLink = form.querySelector('[data-burnout-filters-clear]');
    const status = form.querySelector('[data-burnout-filters-status]');
    const overviewTab = document.querySelector('[data-analytics-tab="overview"]');
    let activeRequest;

    if (submitButton) submitButton.hidden = true;

    const urlFor = (params) => {
        const query = params.toString();
        return `${form.action}${query ? `?${query}` : ''}`;
    };

    const currentParams = () => {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((key) => {
            if (!params.get(key)) params.delete(key);
        });
        return params;
    };

    // Keeps everything outside the swapped fragment in step with the URL: the
    // selects (after Back/Forward), the Clear link, and the Overview tab,
    // which carries the department across.
    const syncWith = (url) => {
        const params = new URL(url, window.location.origin).searchParams;
        form.querySelectorAll('select').forEach((select) => {
            select.value = params.get(select.name) || '';
        });
        if (clearLink) clearLink.hidden = ![...params.keys()].some((key) => key !== 'page' && params.get(key));
        if (overviewTab) {
            const overview = new URL(overviewTab.href, window.location.origin);
            overview.search = '';
            if (params.get('department_id')) overview.searchParams.set('department_id', params.get('department_id'));
            overviewTab.href = overview.toString();
        }
    };

    const load = async (url, { pushHistory = true } = {}) => {
        activeRequest?.abort();
        const controller = new AbortController();
        activeRequest = controller;

        results.setAttribute('aria-busy', 'true');
        if (status) status.textContent = 'Updating…';

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                credentials: 'same-origin',
                signal: controller.signal,
            });

            // A signed-out session or a server error: let the browser handle it
            // as a normal page, which shows the login screen or the error.
            if (!response.ok || response.redirected) {
                window.location.assign(url);
                return;
            }

            results.innerHTML = await response.text();
            if (pushHistory) window.history.pushState({ burnoutFilters: true }, '', url);
            syncWith(url);

            if (status) {
                const count = results.querySelector('[data-burnout-count]')?.textContent.trim();
                status.textContent = count ? `Showing ${count}` : '';
            }
        } catch (error) {
            if (error.name === 'AbortError') return;
            window.location.assign(url);
        } finally {
            if (activeRequest === controller) results.removeAttribute('aria-busy');
        }
    };

    const submit = () => load(urlFor(currentParams()));

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submit();
    });

    form.querySelectorAll('select').forEach((select) => select.addEventListener('change', submit));

    clearLink?.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault();
        form.querySelectorAll('select').forEach((select) => { select.value = ''; });
        load(clearLink.href);
    });

    results.addEventListener('click', (event) => {
        const link = event.target.closest('.report-pagination a');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault();
        load(link.href).then(() => {
            results.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // The pager's "Page [ n ] of N" box: same in-place refresh as its links.
    results.addEventListener('submit', (event) => {
        const jump = event.target.closest('[data-page-jump]');
        if (!jump) return;
        event.preventDefault();
        const url = new URL(jump.action, window.location.origin);
        url.hash = '';
        url.search = new URLSearchParams(new FormData(jump)).toString();
        load(url.toString()).then(() => {
            results.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    window.addEventListener('popstate', () => {
        load(window.location.href, { pushHistory: false });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeBurnoutFilters);
} else {
    initializeBurnoutFilters();
}
