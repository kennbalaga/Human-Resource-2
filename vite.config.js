import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/style.css',
                'resources/js/app.js',
                'resources/js/script.js',
                // Its own entry rather than part of app.js: it is the only code
                // here with third-party dependencies, and folding it into the
                // shared bundle means anything wrong with them — a missing
                // install on a fresh checkout, most likely — takes every button
                // in the app down with it instead of one panel.
                'resources/js/attendance-qr.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Bind to 127.0.0.1 rather than the default [::1]. Content Security
        // Policy host sources cannot express bracketed IPv6 literals, so a
        // dev server on [::1] can never be whitelisted and is blocked once
        // CSP_MODE is enforced. See config/security.php.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
