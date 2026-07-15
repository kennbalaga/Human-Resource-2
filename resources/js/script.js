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
});
