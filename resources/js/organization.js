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
    document.querySelectorAll('form.organization-filters').forEach((form) => {
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

/* A failed create-employee submit comes back as a normal render of the
   directory, so the modal has to be reopened for the errors and the old input
   inside it to be seen at all. */
const reopenModalsWithErrors = () => {
    document.querySelectorAll('.modal[data-open-on-error]').forEach((element) => {
        window.bootstrap?.Modal.getOrCreateInstance(element).show();
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initializePositionFiltering();
    initializeLiveOrganizationFilters();
    reopenModalsWithErrors();
});
