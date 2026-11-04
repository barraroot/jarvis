import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import VisualizationPanel from '@/components/hud/VisualizationPanel.vue';
import { VISUALIZATION_SCAN_MS } from '@/components/hud/constants';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { Visualization, VisualizationSection } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

const QUESTION: VisualizationSection = {
    key: 'question',
    position: 1,
    label: 'PERGUNTA',
    type: 'question',
    headline: 'Aula de phrasal verbs encontrada',
};

function results(items: Array<{ title: string; subtitle: string; badges: string[] }>, status: 'syncing' | 'updated' = 'syncing'): VisualizationSection {
    return { key: 'results', position: 2, label: 'RESULTADOS', type: 'results', status, items };
}

function makeVisualization(overrides: Partial<Visualization> = {}): Visualization {
    return {
        id: 1,
        task_id: 5,
        title: 'Busca no blog · aula de inglês',
        source: 'Beer And Code · MCP do blog',
        status: 'syncing',
        created_at: new Date(2026, 10, 4, 9, 41).toISOString(),
        updated_at: new Date(2026, 10, 4, 9, 43).toISOString(),
        closed_at: null,
        sections: [QUESTION, results([])],
        ...overrides,
    };
}

function reducedMotion(enabled: boolean): void {
    vi.spyOn(window, 'matchMedia').mockImplementation(
        (query) =>
            ({
                matches: enabled && query.includes('prefers-reduced-motion'),
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            }) as unknown as MediaQueryList,
    );
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('VisualizationPanel', () => {
    it('renders nothing without an open visualization', () => {
        const wrapper = mount(VisualizationPanel);

        expect(wrapper.find('[data-testid="visualization-panel"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('shows the header, 01 PERGUNTA and the results skeleton with SINCRONIZANDO while syncing without items', () => {
        useVisualizationsStore().apply(makeVisualization());

        const wrapper = mount(VisualizationPanel);

        expect(wrapper.get('[data-testid="viz-label"]').text()).toBe('VISUALIZAÇÃO 09:41');
        expect(wrapper.get('[data-testid="viz-title"]').text()).toBe('Busca no blog · aula de inglês');
        expect(wrapper.get('[data-testid="viz-source"]').text()).toBe('Beer And Code · MCP do blog');
        expect(wrapper.get('[data-testid="viz-status"]').text()).toBe('SINCRONIZANDO · Beer And Code · MCP do blog');

        const headings = wrapper.findAll('[data-testid="viz-section-heading"]').map((heading) => heading.text().replace(/\s+/g, ' '));
        expect(headings).toEqual(['01 PERGUNTA', '02 RESULTADOS']);
        expect(wrapper.get('[data-testid="viz-question-headline"]').text()).toBe('Aula de phrasal verbs encontrada');
        expect(wrapper.find('[data-testid="viz-results-skeleton"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('replaces the skeleton with the numbered list and shows MELHOR RESULTADO for best', async () => {
        const store = useVisualizationsStore();
        store.apply(makeVisualization());

        const wrapper = mount(VisualizationPanel);

        store.apply(
            makeVisualization({
                status: 'updated',
                sections: [
                    QUESTION,
                    results(
                        [
                            { title: 'Phrasal verbs no trabalho', subtitle: 'beerandcode.com.br/aulas/42', badges: ['best', 'REALTIME'] },
                            { title: 'Verbos irregulares', subtitle: 'beerandcode.com.br/aulas/12', badges: [] },
                        ],
                        'updated',
                    ),
                ],
            }),
        );
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="viz-results-skeleton"]').exists()).toBe(false);

        const items = wrapper.findAll('[data-testid="viz-result-item"]');
        expect(items).toHaveLength(2);
        expect(items[0]?.text()).toContain('01');
        expect(items[0]?.get('[data-testid="viz-result-subtitle"]').classes()).toContain('font-mono');
        expect(items[0]?.get('[data-testid="viz-result-best"]').text()).toBe('MELHOR RESULTADO');
        expect(items[0]?.get('[data-testid="viz-result-badge"]').text()).toBe('REALTIME');
        expect(items[0]?.text()).not.toContain('best');
        expect(items[1]?.find('[data-testid="viz-result-best"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="viz-status"]').text()).toBe('ATUALIZADO 09:43');

        wrapper.unmount();
    });

    it('shows the scan bar before a new section 03 and then types its text', async () => {
        reducedMotion(false);
        vi.useFakeTimers();
        const store = useVisualizationsStore();
        store.apply(makeVisualization());

        const wrapper = mount(VisualizationPanel);

        store.apply(
            makeVisualization({
                sections: [
                    QUESTION,
                    results([]),
                    { key: 'lesson_link', position: 3, label: 'LINK DA AULA', type: 'text', text: 'Veja em https://beerandcode.com.br/aulas/42.' },
                ],
            }),
        );
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="scan-bar"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="viz-section-lesson_link"]').exists()).toBe(false);

        await vi.advanceTimersByTimeAsync(VISUALIZATION_SCAN_MS);

        expect(wrapper.find('[data-testid="scan-bar"]').exists()).toBe(false);
        const section = wrapper.get('[data-testid="viz-section-lesson_link"]');
        expect(section.get('[data-testid="viz-section-heading"]').text().replace(/\s+/g, ' ')).toBe('03 LINK DA AULA');
        expect(section.get('[data-testid="viz-text"]').attributes('data-typing')).toBe('true');

        await vi.advanceTimersByTimeAsync(2000);

        const text = section.get('[data-testid="viz-text"]');
        expect(text.attributes('data-typing')).toBe('false');
        expect(text.text()).toBe('Veja em https://beerandcode.com.br/aulas/42.');

        const link = text.get('[data-testid="viz-text-link"]');
        expect(link.attributes('href')).toBe('https://beerandcode.com.br/aulas/42');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');

        wrapper.unmount();
    });

    it('skips the scan bar and typing with prefers-reduced-motion', async () => {
        reducedMotion(true);
        const store = useVisualizationsStore();
        store.apply(makeVisualization());

        const wrapper = mount(VisualizationPanel);

        store.apply(
            makeVisualization({
                sections: [QUESTION, results([]), { key: 'note', position: 3, label: 'NOTA', type: 'text', text: 'Pronto.' }],
            }),
        );
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="scan-bar"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="viz-text"]').text()).toBe('Pronto.');
        expect(wrapper.html()).not.toContain('animate-pulse');

        wrapper.unmount();
    });

    it('renders markup as text and links only http(s) URLs', () => {
        useVisualizationsStore().apply(
            makeVisualization({
                title: '<img src=x onerror=alert(1)>',
                sections: [
                    { ...QUESTION, headline: '<script>alert(1)</script>' } as VisualizationSection,
                    results([{ title: '<b>negrito</b>', subtitle: '<i>x</i>', badges: ['<svg>'] }], 'updated'),
                    { key: 'text', position: 3, label: 'TEXTO', type: 'text', text: 'javascript:alert(1) <a href="x">y</a>' },
                ],
            }),
        );

        const wrapper = mount(VisualizationPanel);

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.find('[data-testid="viz-text"] a').exists()).toBe(false);
        expect(wrapper.get('[data-testid="viz-question-headline"]').text()).toBe('<script>alert(1)</script>');
        expect(wrapper.get('[data-testid="viz-title"]').text()).toBe('<img src=x onerror=alert(1)>');
        expect(wrapper.get('[data-testid="viz-result-title"]').text()).toBe('<b>negrito</b>');
        expect(wrapper.get('[data-testid="viz-text"]').text()).toBe('javascript:alert(1) <a href="x">y</a>');

        wrapper.unmount();
    });

    it('calls CT-09 and closes the panel on ×', async () => {
        const { calls } = mockFetch({
            'POST /api/v1/visualizations/1/close': { body: { data: makeVisualization({ closed_at: new Date().toISOString() }) } },
        });
        useVisualizationsStore().apply(makeVisualization());

        const wrapper = mount(VisualizationPanel);

        await wrapper.get('[data-testid="viz-close"]').trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/visualizations/1/close')).toBe(true);
        expect(calls.some((call) => call.url.includes('/tasks/'))).toBe(false);
        expect(wrapper.find('[data-testid="visualization-panel"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
