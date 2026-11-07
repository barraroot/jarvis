import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import App from '@/App.vue';
import HudLayout from '@/layouts/HudLayout.vue';
import { createAppRouter } from '@/router';
import { useConnectionStore } from '@/stores/connection';
import { useConversationsStore } from '@/stores/conversations';
import { useLimitsStore } from '@/stores/limits';
import { useVoiceStore, type VoiceUnavailableReason } from '@/stores/voice';
import { makeUser, mockFetch } from '../helpers/fetch';

const Blank = { render: () => null };

async function mountLayout() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'hud', component: Blank },
            { path: '/settings', name: 'settings', component: Blank },
            { path: '/memories', name: 'memories', component: Blank },
            { path: '/approvals', name: 'approvals', component: Blank },
            { path: '/activity', name: 'activity', component: Blank },
        ],
    });
    await router.push('/settings');

    return mount(HudLayout, { global: { plugins: [router] } });
}

const REASONS: Record<VoiceUnavailableReason, string> = {
    no_realtime: 'Nenhum modelo de voz em tempo real está configurado.',
    unsupported: 'Este navegador não suporta voz em tempo real.',
    other_tab: 'A voz está ativa em outra aba.',
    mic_denied: 'Microfone sem permissão ou bloqueado.',
};

describe('Edge states — offline (RF-10)', () => {
    it('disables the composer and never promises a send queue', async () => {
        const wrapper = await mountLayout();
        const connection = useConnectionStore();

        connection.state = 'disconnected';
        await nextTick();

        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeDefined();

        const offline = wrapper.get('[data-testid="disconnected-banner"]');
        expect(offline.text()).toContain('Offline');
        expect(offline.text()).toContain('O envio está desativado até reconectar.');
        expect(offline.text().toLowerCase()).not.toContain('fila');
        expect(offline.find('[data-testid="edge-offline-reconnecting"]').exists()).toBe(false);
    });

    it('shows the reconnection countdown from nextRetryDelayMs', async () => {
        const wrapper = await mountLayout();
        const connection = useConnectionStore();

        connection.state = 'disconnected';
        connection.nextRetryDelayMs = 3000;
        await nextTick();

        expect(wrapper.get('[data-testid="edge-offline-reconnecting"]').text()).toBe('Reconectando… 3 s');

        connection.nextRetryDelayMs = 2500;
        await nextTick();
        expect(wrapper.get('[data-testid="edge-offline-reconnecting"]').text()).toBe('Reconectando… 3 s');
    });

    it('hides the voice edge state while offline', async () => {
        const wrapper = await mountLayout();
        useVoiceStore().markUnavailable('unsupported');
        useConnectionStore().state = 'disconnected';
        await nextTick();

        expect(wrapper.find('[data-testid="voice-unavailable-edge"]').exists()).toBe(false);
    });
});

describe('Edge states — monthly cost limit (RF-09)', () => {
    it('shows exactly the server message from a 402 on send and keeps the composer usable', async () => {
        const serverMessage = 'Você atingiu o limite mensal configurado pelo administrador.';
        mockFetch({
            'GET /api/v1/auth/me': { body: { data: makeUser() } },
            'POST /api/v1/conversations/7/messages': { status: 402, body: { message: serverMessage } },
        });

        const router = createAppRouter(createMemoryHistory());
        await router.push('/settings');
        await router.isReady();
        const wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body });
        await flushPromises();

        useConnectionStore().state = 'connected';
        useConversationsStore().activeId = '7';
        await nextTick();

        const textarea = wrapper.get('[data-testid="layout-footer-controls"] textarea');
        await textarea.setValue('Olá');
        await wrapper.get('[data-testid="footer-send"]').trigger('click');
        await flushPromises();

        const edge = wrapper.get('[data-testid="cost-limit-edge"]');
        expect(edge.get('[data-testid="edge-state-text"]').text()).toBe(serverMessage);
        expect(edge.text()).toContain('Limite mensal atingido');
        expect(edge.text()).not.toContain('R$');
        expect(useLimitsStore().costLimitMessage).toBe(serverMessage);

        expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeUndefined();
        await textarea.setValue('continuo digitando');
        expect((textarea.element as HTMLTextAreaElement).value).toBe('continuo digitando');

        await edge.get('[data-testid="cost-limit-dismiss"]').trigger('click');
        expect(wrapper.find('[data-testid="cost-limit-edge"]').exists()).toBe(false);
        expect(useLimitsStore().costLimitMessage).toBeNull();

        wrapper.unmount();
    });
});

describe('Edge states — voice unavailable (UI-22)', () => {
    it.each(Object.entries(REASONS) as [VoiceUnavailableReason, string][])(
        'explains %s in pt-BR and keeps the text chat enabled',
        async (reason, text) => {
            const wrapper = await mountLayout();
            useConnectionStore().state = 'connected';
            useVoiceStore().markUnavailable(reason);
            await nextTick();

            const edge = wrapper.get('[data-testid="voice-unavailable-edge"]');
            expect(edge.attributes('data-reason')).toBe(reason);
            expect(edge.text()).toContain('Voz indisponível');
            expect(edge.text()).toContain(text);
            expect(edge.text()).toContain('O chat por texto segue normal.');
            expect(edge.find('[data-testid="voice-allow-microphone"]').exists()).toBe(reason === 'mic_denied');
            expect(wrapper.get('[data-testid="layout-footer-controls"]').attributes('disabled')).toBeUndefined();
        },
    );

    it('asks for the microphone again from mic_denied', async () => {
        const wrapper = await mountLayout();
        useConnectionStore().state = 'connected';
        useVoiceStore().markUnavailable('mic_denied');
        await nextTick();

        await wrapper.get('[data-testid="voice-allow-microphone"]').trigger('click');

        expect(wrapper.emitted('allowMicrophone')).toHaveLength(1);
    });
});
