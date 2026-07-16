const initializePositionFiltering = () => {
    document.querySelectorAll('[data-employee-assignment]').forEach((form) => {
        const department = form.querySelector('[data-department-select]');
        const position = form.querySelector('[data-position-select]');

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

        filterPositions();
        department.addEventListener('change', () => filterPositions(true));
    });
};

document.addEventListener('DOMContentLoaded', initializePositionFiltering);
