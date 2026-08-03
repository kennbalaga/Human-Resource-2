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
