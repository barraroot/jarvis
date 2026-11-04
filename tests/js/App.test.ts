import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import App from '@/App.vue';
import { createAppRouter } from '@/router';
import { DEFAULT_PREFERENCES } from '@/stores/preferences';
import { useVoiceStore } from '@/stores/voice';
import { makeUser, mockFetch } from './helpers/fetch';

async function mountApp(path: string) {
    const router = createAppRouter(createMemoryHistory());
    await router.push(path);
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();

    return { wrapper, router };
}

describe('App smoke test', () => {
    it('redirects a protected route to login when there is no user', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { status: 401, body: { message: 'Unauthenticated.' } } });

        const { wrapper, router } = await mountApp('/settings');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/settings');
        await vi.waitFor(() => expect(wrapper.find('[data-testid="login-form"]').exists()).toBe(true));
        expect(wrapper.find('[data-testid="hud-layout"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('renders the HUD shell for an authenticated user', async () => {
        mockFetch({ 'GET /api/v1/auth/me': { body: { data: makeUser() } } });

        const { wrapper, router } = await mountApp('/');

        expect(router.currentRoute.value.name).toBe('hud');
        expect(wrapper.find('[data-testid="hud-layout"]').exists()).toBe(true);
        await vi.waitFor(() => expect(wrapper.find('[data-testid="conversation-view"]').exists()).toBe(true));
        expect(wrapper.get('[data-testid="conversation-greeting"]').text()).toContain('Saudações');

        wrapper.unmount();
    });

    it('wires the realtime voice instead of the browser STT/TTS', async () => {
        const speechSynthesis = { speak: vi.fn(), cancel: vi.fn(), getVoices: () => [], addEventListener: vi.fn(), removeEventListener: vi.fn() };
        vi.stubGlobal('speechSynthesis', speechSynthesis);
        mockFetch({
            'GET /api/v1/auth/me': { body: { data: makeUser({ preference: { ...DEFAULT_PREFERENCES, wake_word_enabled: true, wake_word_consent_valid: true } }) } },
        });

        const { wrapper } = await mountApp('/');

        // happy-dom has no WebRTC: voice is unavailable and the HUD is text-only (RF-08a).
        expect(useVoiceStore().unavailableReason).toBe('unsupported');
        expect(wrapper.get('[data-testid="footer-mute"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="composer"]').exists()).toBe(true);
        expect(speechSynthesis.speak).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('no source imports the removed browser STT/TTS composables', () => {
        const root = join(process.cwd(), 'resources/js');
        const offenders = sourceFiles(root).filter((file) => /useVoiceInput|useSpeechOutput|speechSynthesis\.speak/.test(readFileSync(file, 'utf8')));

        expect(offenders.map((file) => relative(root, file))).toEqual([]);
    });
});

function sourceFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);

        return statSync(path).isDirectory() ? sourceFiles(path) : /\.(ts|vue)$/.test(path) ? [path] : [];
    });
}
