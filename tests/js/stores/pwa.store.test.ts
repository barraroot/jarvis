import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { registerSW } from 'virtual:pwa-register';
import UpdatePrompt from '@/components/hud/UpdatePrompt.vue';
import { usePwaStore } from '@/stores/pwa';

const registerSWMock = vi.mocked(registerSW);

type RegisterOptions = NonNullable<Parameters<typeof registerSW>[0]>;

function registeredOptions(): RegisterOptions {
    const options = registerSWMock.mock.calls.at(-1)?.[0];

    if (options === undefined) {
        throw new Error('registerSW was not called');
    }

    return options;
}

describe('pwa store — service worker', () => {
    beforeEach(() => {
        registerSWMock.mockClear();
        Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: {} });
    });

    afterEach(() => {
        Reflect.deleteProperty(navigator, 'serviceWorker');
    });

    it('registers the worker once in prompt mode', () => {
        const pwa = usePwaStore();

        pwa.registerServiceWorker();
        pwa.registerServiceWorker();

        expect(registerSWMock).toHaveBeenCalledTimes(1);
        expect(registeredOptions().immediate).toBe(true);
    });

    it('skips registration when the browser has no service worker support', () => {
        Reflect.deleteProperty(navigator, 'serviceWorker');

        usePwaStore().registerServiceWorker();

        expect(registerSWMock).not.toHaveBeenCalled();
    });

    it('shows the "nova versão disponível" prompt and only reloads when the operator accepts', async () => {
        const updateServiceWorker = vi.fn(async () => undefined);
        registerSWMock.mockReturnValueOnce(updateServiceWorker);

        const pwa = usePwaStore();
        pwa.registerServiceWorker();

        const wrapper = mount(UpdatePrompt);
        expect(wrapper.find('[data-testid="pwa-update-prompt"]').exists()).toBe(false);

        registeredOptions().onNeedRefresh?.();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="pwa-update-prompt"]').text()).toContain('Nova versão disponível');
        expect(updateServiceWorker).not.toHaveBeenCalled();

        await wrapper.find('[data-testid="pwa-update-apply"]').trigger('click');

        expect(updateServiceWorker).toHaveBeenCalledWith(true);
        expect(wrapper.find('[data-testid="pwa-update-prompt"]').exists()).toBe(false);
    });

    it('dismisses the prompt without applying the update', async () => {
        const updateServiceWorker = vi.fn(async () => undefined);
        registerSWMock.mockReturnValueOnce(updateServiceWorker);

        const pwa = usePwaStore();
        pwa.registerServiceWorker();
        registeredOptions().onNeedRefresh?.();

        const wrapper = mount(UpdatePrompt);
        await wrapper.find('[data-testid="pwa-update-dismiss"]').trigger('click');

        expect(pwa.needRefresh).toBe(false);
        expect(updateServiceWorker).not.toHaveBeenCalled();
    });
});
