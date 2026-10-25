import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/ops.css',
                'resources/js/ops/app.js',
                'resources/js/admin.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
        // Workbox service worker (resources/js/sw.js) → public/build/sw.js, served at /sw.js.
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.js',
            outDir: 'public/build',
            injectRegister: false,
            manifest: false, // served dynamically (localised) by PwaController
            injectManifest: {
                globDirectory: 'public/build',
                globPatterns: ['assets/**/*.{js,css,woff2,svg,png}'],
                // Keep the first install small on low-end phones: no admin theme,
                // no font subsets the three languages never use.
                globIgnores: [
                    'assets/theme-*.css',
                    'assets/admin-*.js',
                    'assets/*-{cyrillic,cyrillic-ext,greek,greek-ext,vietnamese}-*.woff2',
                ],
                // Asset URLs are relative to /build/, but the worker runs at /.
                modifyURLPrefix: { '': '/build/' },
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
            },
            devOptions: { enabled: false },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
})
