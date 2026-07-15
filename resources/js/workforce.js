document.addEventListener('DOMContentLoaded', () => {
    const leaveForm = document.querySelector('#leaveRequestModal form');
    if (leaveForm) {
        const start = leaveForm.elements.start_date;
        const end = leaveForm.elements.end_date;
        const syncDates = () => {
            end.min = start.value;
            if (end.value && end.value < start.value) end.value = start.value;
        };
        start.addEventListener('change', syncDates);
        syncDates();
    }

    const observedBars = document.querySelectorAll('.analytics-panel .bar-track i, .analytics-panel .horizontal-track i');
    if (observedBars.length && 'IntersectionObserver' in window) {
        observedBars.forEach((bar) => {
            const width = bar.style.width;
            const height = bar.style.height;
            if (width) bar.style.width = '0';
            if (height) bar.style.height = '0';
            bar.dataset.targetWidth = width;
            bar.dataset.targetHeight = height;
        });
        const observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const bar = entry.target;
            if (bar.dataset.targetWidth) bar.style.width = bar.dataset.targetWidth;
            if (bar.dataset.targetHeight) bar.style.height = bar.dataset.targetHeight;
            observer.unobserve(bar);
        }), { threshold: 0.15 });
        observedBars.forEach((bar) => observer.observe(bar));
    }
});
