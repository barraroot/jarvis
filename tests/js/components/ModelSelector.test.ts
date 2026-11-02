import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ModelSelector from '@/components/hud/ModelSelector.vue';
import { useConversationsStore } from '@/stores/conversations';
import { mockFetch } from '../helpers/fetch';

function makeModel(id: string, name: string, provider: string, isDefault = false) {
    return { id, name, provider, is_default: isDefault };
}

const modelsRoute = {
    body: {
        data: [
            makeModel('m1', 'GPT-4o', 'openai', true),
            makeModel('m2', 'Claude', 'anthropic'),
        ],
    },
};

function mountSelector(modelId: string | null = null) {
    return mount(ModelSelector, { props: { conversationId: 'c1', modelId } });
}

describe('ModelSelector', () => {
    it('lists the available models and marks the brain default when the conversation has none', async () => {
        mockFetch({ 'GET /api/v1/ai/models': modelsRoute });
        const wrapper = mountSelector();
        await flushPromises();

        const options = wrapper.findAll('option');
        expect(options.map((option) => option.text())).toEqual(['selecione', 'GPT-4o · Padrão', 'Claude']);
        expect(wrapper.get('select').element.value).toBe('m1');

        wrapper.unmount();
    });

    it('marks the model pinned to the conversation', async () => {
        mockFetch({ 'GET /api/v1/ai/models': modelsRoute });
        const wrapper = mountSelector('m2');
        await flushPromises();

        expect(wrapper.get('select').element.value).toBe('m2');

        wrapper.unmount();
    });

    it('saves the choice through PATCH and updates the conversation store', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/ai/models': modelsRoute,
            'PATCH /api/v1/conversations/c1': {
                body: {
                    data: {
                        id: 'c1',
                        title: 'Plano',
                        last_activity_at: '2026-11-02T10:00:00.000000Z',
                        ai_model_id: 'm2',
                        model: makeModel('m2', 'Claude', 'anthropic'),
                    },
                },
            },
        });
        const wrapper = mountSelector('m1');
        await flushPromises();

        useConversationsStore().conversations.push({
            id: 'c1',
            title: 'Plano',
            last_activity_at: '2026-11-02T10:00:00.000000Z',
        });

        await wrapper.get('select').setValue('m2');
        await flushPromises();

        const patch = calls.find((call) => call.method === 'PATCH' && call.url === '/api/v1/conversations/c1');
        expect(patch).toBeDefined();
        expect(JSON.parse(patch!.init!.body as string)).toEqual({ ai_model_id: 'm2' });
        expect(useConversationsStore().conversations[0]?.model?.name).toBe('Claude');

        wrapper.unmount();
    });

    it('shows an i18n error when the model was deactivated (422)', async () => {
        mockFetch({
            'GET /api/v1/ai/models': modelsRoute,
            'PATCH /api/v1/conversations/c1': {
                status: 422,
                body: { message: 'The selected ai model id is invalid.', errors: { ai_model_id: ['inactive'] } },
            },
        });
        const wrapper = mountSelector('m1');
        await flushPromises();

        await wrapper.get('select').setValue('m2');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Modelo indisponível — ele pode ter sido desativado. Escolha outro.');

        wrapper.unmount();
    });
});
