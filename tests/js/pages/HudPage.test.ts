import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import HudLayout from '@/layouts/HudLayout.vue';
import HudPage from '@/pages/HudPage.vue';
import { useConversationsStore } from '@/stores/conversations';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { ApprovalSummary, TaskSummary, Visualization } from '@/types/models';
import { mockFetch } from '../helpers/fetch';
import { mockViewport } from '../helpers/matchMedia';

function makeVisualization(overrides: Partial<Visualization> = {}): Visualization {
    return {
        id: 12,
        task_id: 30,
        title: 'Busca no blog',
        source: 'Beer And Code',
        status: 'updated',
        created_at: '2026-11-04T10:00:00Z',
        updated_at: '2026-11-04T10:02:00Z',
        closed_at: null,
        sections: [{ key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Aula de inglês' }],
        ...overrides,
    };
}

function makeTask(): TaskSummary {
    return {
        id: '30',
        title: 'Pesquisar aulas',
        status: 'running',
        progress: 40,
        agent: null,
        model: null,
        visualization_id: 12,
        voice_summary: null,
        started_at: '2026-11-04T10:00:00Z',
        created_at: '2026-11-04T10:00:00Z',
    };
}

function makeApproval(overrides: Partial<ApprovalSummary> = {}): ApprovalSummary {
    return {
        id: 'a1',
        status: 'pending',
        tool: { identifier: 'mail.send', name: 'Enviar e-mail', description: 'Envia um e-mail em seu nome.' },
        effect: { is_write: true, is_sensitive: false, external: true },
        params: { to: 'ana@example.com' },
        agent: 'Secretária',
        requested_at: '2026-11-04T10:00:00Z',
        expires_at: new Date(Date.now() + 3600_000).toISOString(),
        decided_at: null,
        decision_reason: null,
        ...overrides,
    };
}

const NO_APPROVALS = { 'GET /api/v1/approvals?status=pending': { body: { data: [] } } };

/** HudPage inside the real layout, as after a page reload on `/`. */
async function mountHud() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'hud', component: HudPage },
            { path: '/settings', name: 'settings', component: HudPage },
        ],
    });
    await router.push('/');

    const wrapper = mount(
        { components: { HudLayout }, template: '<HudLayout><template #main><RouterView /></template></HudLayout>' },
        { global: { plugins: [router] } },
    );
    await flushPromises();

    return wrapper;
}

describe('HudPage', () => {
    it('restores the open visualization (CT-08) and the active tasks on mount', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/visualizations/current': { body: { data: makeVisualization() } },
            'GET /api/v1/tasks?active=1': { body: { data: [makeTask()] } },
            ...NO_APPROVALS,
        });

        const wrapper = mount(HudPage);
        await flushPromises();

        expect(useVisualizationsStore().current?.id).toBe(12);
        expect(useTasksStore().tasks.map((task) => task.id)).toEqual(['30']);
        expect(calls.filter((call) => call.url === '/api/v1/visualizations/current')).toHaveLength(1);

        wrapper.unmount();
    });

    it('closes any stale conversation and hides the duplicated greeting core', async () => {
        mockFetch({ 'GET /api/v1/visualizations/current': { body: { data: null } }, ...NO_APPROVALS });
        const conversations = useConversationsStore();
        conversations.activeId = 'c9';

        const wrapper = mount(HudPage);
        await flushPromises();

        expect(conversations.activeId).toBeNull();
        expect(wrapper.find('[data-testid="conversation-greeting"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="conversation-greeting"] [data-testid="jarvis-core"]').exists()).toBe(false);
        expect(useVisualizationsStore().current).toBeNull();

        wrapper.unmount();
    });

    it('a reload with an open visualization shows the same id in the corner layout (RF-19b)', async () => {
        mockViewport(1280);
        mockFetch({
            'GET /api/v1/visualizations/current': { body: { data: makeVisualization({ id: 77, title: 'Restaurada' }) } },
            'GET /api/v1/tasks?active=1': { body: { data: [] } },
            ...NO_APPROVALS,
        });

        const wrapper = await mountHud();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('corner');
        expect(useVisualizationsStore().current?.id).toBe(77);
        expect(wrapper.get('[data-testid="viz-title"]').text()).toBe('Restaurada');

        wrapper.unmount();
    });

    it('keeps the core centered when there is nothing to restore', async () => {
        mockViewport(1280);
        mockFetch({
            'GET /api/v1/visualizations/current': { body: { data: null } },
            'GET /api/v1/tasks?active=1': { body: { data: [] } },
            ...NO_APPROVALS,
        });

        const wrapper = await mountHud();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('center');
        expect(wrapper.find('[data-testid="hud-stage-visualization"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows the pending approvals on the HUD in focus mode (UI-13)', async () => {
        mockViewport(1280);
        const { calls } = mockFetch({
            'GET /api/v1/visualizations/current': { body: { data: null } },
            'GET /api/v1/tasks?active=1': { body: { data: [] } },
            'GET /api/v1/approvals?status=pending': {
                body: { data: [makeApproval(), makeApproval({ id: 'a2', tool: { identifier: 'cal.add', name: 'Criar evento', description: 'Cria um evento.' } })] },
            },
        });

        const wrapper = await mountHud();
        const section = wrapper.get('[data-testid="hud-stage-content"] [data-testid="hud-approvals"]');

        expect(calls.filter((call) => call.url === '/api/v1/approvals?status=pending')).toHaveLength(1);
        expect(section.findAll('[data-testid="approval-card"]')).toHaveLength(2);
        expect(section.text()).toContain('Enviar e-mail');
        expect(section.text()).toContain('Criar evento');

        wrapper.unmount();
    });

    it('renders no approval section when nothing is pending', async () => {
        mockFetch({ 'GET /api/v1/visualizations/current': { body: { data: null } }, ...NO_APPROVALS });

        const wrapper = mount(HudPage);
        await flushPromises();

        expect(wrapper.find('[data-testid="hud-approvals"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
