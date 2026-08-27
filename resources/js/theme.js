const root = document.documentElement;
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');

function resolveTheme(theme) {
    return theme === 'system' ? (systemTheme.matches ? 'dark' : 'light') : theme;
}

/*
 * Against `theme`, the stored preference, and never `themeResolved`.
 *
 * The two differ precisely on `system`, which resolves to light or dark and so
 * would never match its own button -- leaving the option the user is actually
 * on reading as unselected, and one of the other two falsely reading as chosen.
 */
function updateThemeOptions() {
    const selected = root.dataset.theme || 'system';

    document.querySelectorAll('[data-theme-set]').forEach((button) => {
        button.setAttribute('aria-pressed', selected === button.dataset.themeSet ? 'true' : 'false');
    });
}

function applyTheme(theme) {
    root.dataset.theme = theme;
    root.dataset.themeResolved = resolveTheme(theme);
    updateThemeOptions();
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

async function selectTheme(button, next) {
    const previous = root.dataset.theme || 'system';

    /*
     * Also the stored value. Comparing the resolved one dropped the change
     * whenever the new choice already matched what was on screen: on System
     * with a dark device, choosing Dark hit `resolved === 'dark'` and returned
     * here, so nothing was saved. The screen looked right, and the preference
     * stayed System -- so the app followed the device back to light later,
     * against an explicit choice the user had made and watched appear to land.
     */
    if (previous === next) {
        return;
    }

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
}

document.querySelectorAll('[data-theme-set]').forEach((button) => {
    button.addEventListener('click', () => selectTheme(button, button.dataset.themeSet));
});

document.querySelectorAll('input[name="theme"]').forEach((input) => {
    input.addEventListener('change', () => applyTheme(input.value));
});

systemTheme.addEventListener('change', () => {
    if (root.dataset.theme === 'system') {
        applyTheme('system');
    }
});

updateThemeOptions();
