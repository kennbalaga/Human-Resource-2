document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('attendanceApp');
    const clockElement = document.getElementById('liveAttendanceClock');

    if (!app || !clockElement) {
        return;
    }

    const clockFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: app.dataset.officeTimezone || 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });

    const updateClock = () => {
        clockElement.textContent = clockFormatter.format(new Date());
    };

    updateClock();
    window.setInterval(updateClock, 1000);
});
