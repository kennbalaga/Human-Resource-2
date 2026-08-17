document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-toggle-password], #togglePassword').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const inputId = this.dataset.togglePassword || 'password';
            const passwordInput = document.getElementById(inputId);

            if (!passwordInput) {
                return;
            }

            const type = passwordInput.type === 'password' ? 'text' : 'password';
            passwordInput.type = type;

            const icon = this.matches('i') ? this : this.querySelector('i');
            icon?.classList.toggle('fa-eye');
            icon?.classList.toggle('fa-eye-slash');

            if (this.matches('button')) {
                this.setAttribute('aria-label', type === 'password' ? 'Show password' : 'Hide password');
            }
        });
    });

    // Rendered only when the server has something to explain about the session
    // that just ended, so its presence is the whole condition for showing it.
    const sessionDialog = document.querySelector('[data-auth-dialog]');

    if (sessionDialog) {
        // showModal over the open attribute: it takes focus, traps it, and
        // dims the form behind, which the attribute alone does none of.
        sessionDialog.showModal();

        sessionDialog.querySelector('[data-auth-dialog-close]')?.addEventListener('click', () => {
            sessionDialog.close();
            document.getElementById('password')?.focus();
        });

        // Leaving the reason in the address bar survives a refresh and a shared
        // link, and would announce the same ended session again tomorrow.
        const url = new URL(window.location.href);

        if (url.searchParams.has('reason')) {
            url.searchParams.delete('reason');
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        }
    }
});
