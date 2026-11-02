import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import { i18n } from '@/i18n';
import { createAppRouter } from '@/router';
import { usePwaStore } from '@/stores/pwa';

const app = createApp(App);

const pinia = createPinia();

app.use(pinia);
app.use(i18n);
app.use(createAppRouter());

const pwa = usePwaStore(pinia);
// `beforeinstallprompt` may fire before the settings page exists; capture it from boot.
pwa.captureInstallPrompt();

app.mount('#app');

pwa.registerServiceWorker();
