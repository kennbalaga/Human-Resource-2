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

        const previewEmployeeNumber = () => {
            if (!generatedEmployeeNumber) return;

            const departmentCode = department.selectedOptions[0]?.dataset.departmentCode;
            const hireYear = hireDate?.value?.slice(0, 4) || new Date().getFullYear();
            generatedEmployeeNumber.value = departmentCode
                ? `${departmentCode}-${hireYear}-[next]`
                : 'Select a department first';
        };

        filterPositions();
        previewEmployeeNumber();
        department.addEventListener('change', () => {
            filterPositions(true);
            previewEmployeeNumber();
        });
        hireDate?.addEventListener('change', previewEmployeeNumber);
    });
};

document.addEventListener('DOMContentLoaded', initializePositionFiltering);
