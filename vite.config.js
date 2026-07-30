import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                google('Baloo 2', { alias: 'baloo', weights: [600, 700, 800], optimizedFallbacks: false }),
                google('Outfit', { alias: 'outfit', weights: [300, 400, 500, 600, 700], optimizedFallbacks: false }),
                google('JetBrains Mono', { alias: 'mono', weights: [500], optimizedFallbacks: false }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
