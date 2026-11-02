import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import SidePanel from '@/components/hud/SidePanel.vue';
import { mockFetch } from '../helpers/fetch';

function makeModel(id: string, name: string, provider: string) {
    return { id, name, provider, is_default: false };
}

const Blank = { render: () => null };

async function mountPanel() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/memories', name: 'memories', component: Blank }],
    });
    await router.push('/memories');
    await router.isReady();

    return mount(SidePanel, { global: { plugins: [router] } });
}

describe('SidePanel LLM status', () => {
    it('lists connected providers from the models API', async () => {
        mockFetch({
            'GET /api/v1/ai/models': {
                body: {
                    data: [
                        makeModel('m1', 'GPT-4o', 'openai'),
                        makeModel('m2', 'GPT-4o mini', 'openai'),
                        makeModel('m3', 'Claude', 'anthropic'),
                    ],
                },
            },
        });

        const wrapper = await mountPanel();
        await flushPromises();

        const rows = wrapper.findAll('[data-testid="panel-llm-provider"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]?.text()).toContain('openai');
        expect(rows[0]?.text()).toContain('2');
        expect(rows[0]?.text()).toContain('conectado');
        expect(rows[1]?.text()).toContain('anthropic');

        wrapper.unmount();
    });

    it('shows the empty state while no model is connected', async () => {
        mockFetch({ 'GET /api/v1/ai/models': { body: { data: [] } } });

        const wrapper = await mountPanel();
        await flushPromises();

        expect(wrapper.findAll('[data-testid="panel-llm-provider"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('Nenhum modelo conectado');

        wrapper.unmount();
    });
});
