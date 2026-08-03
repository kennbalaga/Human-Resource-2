document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-live-clock]').forEach((clock) => {
        const time = clock.querySelector('[data-live-clock-time]');
        const timezone = clock.dataset.timezone || 'Asia/Manila';

        if (!time) {
            return;
        }

        let formatter;

        try {
            formatter = new Intl.DateTimeFormat(undefined, {
                timeZone: timezone,
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
            });
        } catch (error) {
            formatter = new Intl.DateTimeFormat(undefined, {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
            });
        }

        const updateClock = () => {
            const now = new Date();

            time.textContent = formatter.format(now);
            time.dateTime = now.toISOString();
        };

        updateClock();
        window.setInterval(updateClock, 1000);
    });
});
