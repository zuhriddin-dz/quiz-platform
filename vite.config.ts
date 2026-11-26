import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
        // Shells out to `php artisan wayfinder:generate`, which is why PHP and
        // Node share one container (R0.2).
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        // Bind every interface: this runs inside the `app` container and must
        // be reachable from the host browser.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            // Windows bind mounts do not deliver inotify events into Linux
            // containers, so file watching has to poll.
            usePolling: true,
            interval: 300,
        },
    },
});
