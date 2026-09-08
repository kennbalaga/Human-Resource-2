function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[char]);
}

function renderSection(title, count, rowsHtml, emptyLabel, footerUrl, footerLabel) {
    const rows = count ? rowsHtml : `<p class="global-search-section-empty">${emptyLabel}</p>`;
    const footer = footerUrl
        ? `<a class="global-search-dropdown-footer" href="${footerUrl}">${footerLabel}</a>`
        : '';

    return `
        <div class="global-search-dropdown-section">
            <p class="global-search-dropdown-heading">${title} (${count})</p>
            <div class="global-search-result-list">${rows}</div>
            ${footer}
        </div>
    `;
}

function renderResults(data) {
    const employeeRows = data.employees.map((employee) => `
        <a class="global-search-result" href="${employee.url}" role="option">
            <span class="global-search-result-text">
                <strong>${escapeHtml(employee.name)}</strong>
                <small>${escapeHtml(employee.number)} · ${escapeHtml(employee.department ?? 'Unassigned department')} · ${escapeHtml(employee.position ?? 'Unassigned position')}${employee.archived ? ' · Archived' : ''}</small>
            </span>
        </a>
    `).join('');

    const departmentRows = data.departments.map((department) => `
        <a class="global-search-result" href="${department.url}" role="option">
            <span class="global-search-result-text">
                <strong>${escapeHtml(department.name)}</strong>
                <small>${department.employeesCount} employees · ${department.positionsCount} positions</small>
            </span>
        </a>
    `).join('');

    if (data.employees.length === 0 && data.departments.length === 0) {
        return '<p class="global-search-dropdown-empty">No matches found.</p>';
    }

    return (
        renderSection('Employees', data.employees.length, employeeRows, 'No employee matches.', data.directoryUrl, 'Open directory')
        + renderSection('Departments', data.departments.length, departmentRows, 'No department matches.', data.departmentsUrl, 'Open departments')
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const wrapper = document.querySelector('[data-global-search]');
    if (!wrapper) return;

    const input = wrapper.querySelector('[data-global-search-input]');
    const dropdown = wrapper.querySelector('[data-global-search-dropdown]');

    let debounceTimer = null;
    let activeController = null;

    const closeDropdown = () => {
        dropdown.hidden = true;
        dropdown.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
    };

    const openDropdown = (html) => {
        dropdown.innerHTML = html;
        dropdown.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };

    const runSearch = (query) => {
        if (activeController) activeController.abort();
        activeController = new AbortController();

        fetch(`${wrapper.querySelector('form').action}?q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
            signal: activeController.signal,
        })
            .then((response) => (response.ok ? response.json() : Promise.reject()))
            .then((data) => openDropdown(renderResults(data)))
            .catch((error) => {
                if (error?.name === 'AbortError') return;
                closeDropdown();
            });
    };

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(debounceTimer);

        if (query === '') {
            closeDropdown();
            return;
        }

        debounceTimer = setTimeout(() => runSearch(query), 250);
    });

    input.addEventListener('focus', () => {
        if (input.value.trim() !== '' && dropdown.innerHTML !== '') {
            dropdown.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDropdown();
            input.blur();
        }
    });

    document.addEventListener('click', (event) => {
        if (!wrapper.contains(event.target)) closeDropdown();
    });

    /* Ctrl/Cmd+K still focuses the field; it just no longer advertises itself
       with a chip in the field. K rather than the F the reference bar prints:
       F is find-in-page in every browser, and taking it would cost more than
       the search field is worth. */
    const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'k' && event.key !== 'K') return;
        if (!(isMac ? event.metaKey : event.ctrlKey)) return;
        /* Ctrl+Shift+K and Ctrl+Alt+K belong to the browser's own tools. */
        if (event.shiftKey || event.altKey) return;

        event.preventDefault();
        input.focus();
        input.select();
    });
});
