const root = document.documentElement;
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');

function resolveTheme(theme) {
    return theme === 'system' ? (systemTheme.matches ? 'dark' : 'light') : theme;
}

function updateToggleLabels() {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const next = root.dataset.themeResolved === 'dark' ? 'light' : 'dark';
        const label = `Switch to ${next} mode`;
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    });
}

function applyTheme(theme) {
    root.dataset.theme = theme;
    root.dataset.themeResolved = resolveTheme(theme);
    updateToggleLabels();
}

async function persistTheme(button, theme) {
    const response = await fetch(button.dataset.themeUpdateUrl, {
        method: 'PATCH',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ theme }),
    });

    if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('Unable to save appearance preference.');
    }
}

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', async () => {
        const previous = root.dataset.theme || 'system';
        const next = root.dataset.themeResolved === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        button.disabled = true;

        try {
            await persistTheme(button, next);
            document.querySelectorAll('input[name="theme"]').forEach((input) => {
                input.checked = input.value === next;
            });
        } catch (error) {
            applyTheme(previous);
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('input[name="theme"]').forEach((input) => {
    input.addEventListener('change', () => applyTheme(input.value));
});

systemTheme.addEventListener('change', () => {
    if (root.dataset.theme === 'system') {
        applyTheme('system');
    }
});

updateToggleLabels();
