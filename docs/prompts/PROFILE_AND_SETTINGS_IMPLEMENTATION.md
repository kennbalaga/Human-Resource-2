# Profile and Settings implementation prompt

Use this prompt when reviewing, extending, or recreating the Profile and Settings feature in this HRMS:

> Build complete, production-ready **My Profile** and **Account Settings** features inside the existing Laravel HRMS without recreating the project or changing its established responsive dashboard design. Use Laravel, Blade, Bootstrap, existing reusable UI components, MySQL migrations, server-side validation, session authentication, CSRF protection, and audit logging.
>
> **My Profile:** display the signed-in employee's name, employee ID, role, department, position, supervisor, hire date, employment status, account email, last login, and workforce record counts. HR-managed identity/employment fields must remain read-only. Allow the employee to update only their contact number and home address. Never allow one employee to access or modify another employee's profile through these routes.
>
> **Account Settings:** allow the signed-in user to update a unique valid email, save timezone and notification preferences, enable compact navigation and reduced motion, and change their password after verifying the current password. Require a confirmed password of at least 12 characters containing upper/lowercase letters and a number. Revoke existing API tokens after a password change while preserving the current session.
>
> Store settings in a normalized one-to-one `user_preferences` table with safe defaults. Connect every existing Profile and Settings placeholder link to real named routes. Apply timezone and display preferences in the shared layout. Provide responsive accessible forms, visible validation/success feedback, keyboard-focus states, and mobile layouts. Do not expose password values or personal data in logs.
>
> Add feature tests for authentication, authorization, rendering, contact updates, email uniqueness, preference persistence, current-password validation, password change, token revocation, and navigation links. Run migrations without deleting existing data, then run Laravel Pint, the complete automated test suite, and the production asset build.
