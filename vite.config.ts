import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('JetBrains Mono', {
                    weights: [400, 500, 700],
                    optimizedFallbacks: false,
                }),
                bunny('Exo 2', {
                    weights: [400, 600],
                    optimizedFallbacks: false,
                }),
                bunny('Orbitron', {
                    weights: [500, 700, 900],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            // Laravel builds into public/build, but the worker must live at /sw.js to control scope "/".
            outDir: 'public',
            base: '/',
            scope: '/',
            filename: 'sw.js',
            registerType: 'prompt',
            injectRegister: false,
            // The manifest is served by Laravel (GET /manifest.webmanifest).
            manifest: false,
            includeManifestIcons: false,
            workbox: {
                globDirectory: 'public',
                globPatterns: ['build/assets/**/*.{js,css,woff2,svg,png}', 'icons/*.png'],
                globIgnores: ['**/node_modules/**', 'sw.js', 'workbox-*.js'],
                // Hashed Vite assets are already versioned.
                dontCacheBustURLsMatching: /^build\/assets\//,
                // The HTML shell comes from Laravel, never from the precache.
                navigateFallback: null,
                inlineWorkboxRuntime: true,
                cleanupOutdatedCaches: true,
                clientsClaim: false,
                skipWaiting: false,
                // urlPattern functions are serialized into sw.js: they must not reference outer variables.
                runtimeCaching: [
                    {
                        // Backend-owned paths are never cached (US-2.5): API, Echo auth, Sanctum, Filament.
                        urlPattern: ({ url }) => /^\/(api|broadcasting|sanctum)\/|^\/admin/.test(url.pathname),
                        handler: 'NetworkOnly',
                    },
                    {
                        // Only SPA navigations (the Blade shell); same exclusions as the fallback in routes/web.php.
                        // Every SPA route renders the same shell, so one cache entry ("/") serves them all offline.
                        urlPattern: ({ request, url }) =>
                            request.mode === 'navigate' &&
                            !/^\/(api|admin|broadcasting|sanctum|livewire|filament|storage|build|up)(\/|$)/.test(url.pathname),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'jarvis-shell',
                            networkTimeoutSeconds: 4,
                            cacheableResponse: { statuses: [200] },
                            plugins: [{ cacheKeyWillBeUsed: async () => '/' }],
                        },
                    },
                ],
            },
            devOptions: {
                enabled: false,
            },
        }),
    ],
    define: {
        // vue-i18n 9 compiles messages with `new Function` unless JIT is on; the CSP forbids 'unsafe-eval'.
        __INTLIFY_JIT_COMPILATION__: true,
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
