import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import InstallAppPanel from '@/components/hud/InstallAppPanel.vue';
import { isIosDevice, usePwaStore } from '@/stores/pwa';
import { useToastStore } from '@/stores/toast';

const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const originalUserAgent = navigator.userAgent;

function setUserAgent(userAgent: string, maxTouchPoints = 0): void {
    Object.defineProperty(navigator, 'userAgent', { configurable: true, value: userAgent });
    Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: maxTouchPoints });
}

function fireBeforeInstallPrompt(outcome: 'accepted' | 'dismissed' = 'accepted') {
    const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
        prompt: ReturnType<typeof vi.fn>;
        userChoice: Promise<{ outcome: string; platform: string }>;
        platforms: string[];
    };
    event.prompt = vi.fn(async () => undefined);
    event.userChoice = Promise.resolve({ outcome, platform: 'web' });
    event.platforms = ['web'];

    window.dispatchEvent(event);

    return event;
}

describe('InstallAppPanel (US-2.5)', () => {
    afterEach(() => {
        setUserAgent(originalUserAgent);
    });

    it('stays hidden until the browser offers the install prompt', () => {
        const wrapper = mount(InstallAppPanel);

        expect(wrapper.find('[data-testid="install-app-panel"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows "Instalar JARVIS" once beforeinstallprompt is captured and opens the native prompt', async () => {
        const wrapper = mount(InstallAppPanel);
        const event = fireBeforeInstallPrompt('accepted');
        await flushPromises();

        expect(event.defaultPrevented).toBe(true);
        const button = wrapper.get('[data-testid="install-app-button"]');
        expect(button.text()).toBe('Instalar JARVIS');

        await button.trigger('click');
        await flushPromises();

        expect(event.prompt).toHaveBeenCalledOnce();
        expect(usePwaStore().installed).toBe(true);
        expect(wrapper.find('[data-testid="install-app-button"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="install-app-installed"]').text()).toContain('já está instalado');
        expect(useToastStore().toasts.some((toast) => toast.message === 'JARVIS instalado.')).toBe(true);

        wrapper.unmount();
    });

    it('drops the button after a dismissed prompt (the event is single-use)', async () => {
        const wrapper = mount(InstallAppPanel);
        fireBeforeInstallPrompt('dismissed');
        await flushPromises();

        await wrapper.get('[data-testid="install-app-button"]').trigger('click');
        await flushPromises();

        expect(usePwaStore().installed).toBe(false);
        expect(wrapper.find('[data-testid="install-app-panel"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('hides the button when the app reports it was installed', async () => {
        const wrapper = mount(InstallAppPanel);
        fireBeforeInstallPrompt();
        await flushPromises();

        window.dispatchEvent(new Event('appinstalled'));
        await flushPromises();

        expect(wrapper.find('[data-testid="install-app-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="install-app-installed"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('shows the "Adicionar à Tela de Início" steps on iOS', async () => {
        setUserAgent(IPHONE_UA);

        const wrapper = mount(InstallAppPanel);
        await flushPromises();

        const ios = wrapper.get('[data-testid="install-app-ios"]');
        expect(ios.text()).toContain('Adicionar à Tela de Início');
        expect(wrapper.find('[data-testid="install-app-button"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('detects iPhone and iPadOS (touch Mac) but not desktop browsers', () => {
        setUserAgent(IPHONE_UA);
        expect(isIosDevice()).toBe(true);

        setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 5);
        expect(isIosDevice()).toBe(true);

        setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 0);
        expect(isIosDevice()).toBe(false);

        setUserAgent('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36');
        expect(isIosDevice()).toBe(false);
    });
});
