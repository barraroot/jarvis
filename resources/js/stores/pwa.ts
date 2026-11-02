import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { registerSW } from 'virtual:pwa-register';

/** Chromium's install event (not in the TS DOM lib). */
export interface BeforeInstallPromptEvent extends Event {
    readonly platforms: string[];
    readonly userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
    prompt(): Promise<void>;
}

export type InstallOutcome = 'accepted' | 'dismissed' | 'unavailable';

/** iPhone/iPad Safari has no install prompt: the operator uses "Adicionar à Tela de Início". */
export function isIosDevice(nav: Navigator = navigator): boolean {
    const platform = nav.userAgent;
    // iPadOS 13+ reports itself as a Mac with touch.
    const isTouchMac = /Macintosh/.test(platform) && nav.maxTouchPoints > 1;

    return /iPad|iPhone|iPod/.test(platform) || isTouchMac;
}

/** Already running as an installed app (display-mode standalone or iOS `navigator.standalone`). */
export function isStandaloneDisplay(): boolean {
    const iosStandalone = (navigator as Navigator & { standalone?: boolean }).standalone === true;
    const displayStandalone = typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches;

    return iosStandalone || displayStandalone;
}

/** Re-checks `/sw.js` for a new build while the app stays open. */
export const SERVICE_WORKER_UPDATE_INTERVAL_MS = 60 * 60 * 1000;

/**
 * PWA lifecycle (US-2.5): service worker registration, the "nova versão
 * disponível" prompt (updates are never applied silently) and installation —
 * the deferred `beforeinstallprompt` (desktop/Android) or iOS instructions.
 */
export const usePwaStore = defineStore('pwa', () => {
    const needRefresh = ref(false);
    const offlineReady = ref(false);
    const registered = ref(false);

    const installPromptEvent = ref<BeforeInstallPromptEvent | null>(null);
    const installed = ref(false);
    const ios = ref(false);

    let updateServiceWorker: ((reloadPage?: boolean) => Promise<void>) | null = null;
    let listeningInstall = false;

    /** "Instalar JARVIS" is offered only when the browser handed us the prompt. */
    const canInstall = computed(() => installPromptEvent.value !== null && !installed.value);
    /** iOS has no prompt: show the manual "Adicionar à Tela de Início" steps instead. */
    const showIosInstructions = computed(() => ios.value && !installed.value);

    function onBeforeInstallPrompt(event: Event): void {
        // Keep the browser mini-infobar away; the settings menu offers the install instead.
        event.preventDefault();
        installPromptEvent.value = event as BeforeInstallPromptEvent;
    }

    function onAppInstalled(): void {
        installed.value = true;
        installPromptEvent.value = null;
    }

    /** Listens for the install events from boot, before any page asks (the event fires once). */
    function captureInstallPrompt(): void {
        if (listeningInstall || typeof window === 'undefined') {
            return;
        }

        listeningInstall = true;
        installed.value = isStandaloneDisplay();
        ios.value = typeof navigator !== 'undefined' && isIosDevice();

        window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
        window.addEventListener('appinstalled', onAppInstalled);
    }

    /** Shows the native prompt; the deferred event can only be used once. */
    async function promptInstall(): Promise<InstallOutcome> {
        const event = installPromptEvent.value;

        if (event === null) {
            return 'unavailable';
        }

        installPromptEvent.value = null;
        await event.prompt();
        const choice = await event.userChoice;

        if (choice.outcome === 'accepted') {
            installed.value = true;
        }

        return choice.outcome;
    }

    function registerServiceWorker(): void {
        if (updateServiceWorker !== null || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
            return;
        }

        updateServiceWorker = registerSW({
            immediate: true,
            onNeedRefresh() {
                needRefresh.value = true;
            },
            onOfflineReady() {
                offlineReady.value = true;
            },
            onRegisteredSW(_url, registration) {
                registered.value = true;

                if (registration !== undefined) {
                    setInterval(() => {
                        void registration.update().catch(() => undefined);
                    }, SERVICE_WORKER_UPDATE_INTERVAL_MS);
                }
            },
        });
    }

    /** Activates the waiting worker; the page reloads once it takes control. */
    async function applyUpdate(): Promise<void> {
        needRefresh.value = false;

        await updateServiceWorker?.(true);
    }

    function dismissUpdate(): void {
        needRefresh.value = false;
    }

    return {
        needRefresh,
        offlineReady,
        registered,
        installPromptEvent,
        installed,
        canInstall,
        showIosInstructions,
        registerServiceWorker,
        applyUpdate,
        dismissUpdate,
        captureInstallPrompt,
        promptInstall,
    };
});
