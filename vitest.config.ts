import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            'virtual:pwa-register': fileURLToPath(new URL('./tests/js/helpers/pwa-register.ts', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.ts'],
        // Registers vue-i18n (pt-BR) and a fresh Pinia for every test.
        setupFiles: ['tests/js/setup.ts'],
        restoreMocks: true,
    },
});
