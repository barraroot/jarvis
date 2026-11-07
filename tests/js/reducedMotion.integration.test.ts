import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createMemoryHistory, type Router } from 'vue-router';
import App from '@/App.vue';
import { VISUALIZATION_SCAN_MS } from '@/components/hud/constants';
import { createAppRouter } from '@/router';
import { useAssistantStore } from '@/stores/assistant';
import { useConnectionStore } from '@/stores/connection';
import { useVisualizationsStore } from '@/stores/visualizations';
import type { Visualization, VisualizationSection } from '@/types/models';
import { makeUser, mockFetch } from './helpers/fetch';
import { mockViewport } from './helpers/matchMedia';

/**
 * T24 — integrated reduced-motion check (RNF-01): the authenticated App on the
 * `hud` route, opening and closing a visualization and navigating to
 * `/activity`. Under `prefers-reduced-motion` the core runs no continuous
 * animation nor particles, the stage never calls `animate`, VISUALIZAÇÃO skips
 * scan/typing and the route swaps without transition; the motion variant is
 * the control proving the same flow does apply them.
 */
const QUESTION: VisualizationSection = { key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Aula de inglês' };
const NOTE: VisualizationSection = { key: 'note', position: 2, label: 'NOTA', type: 'text', text: 'Pronto para revisar.' };

function makeVisualization(overrides: Partial<Visualization> = {}): Visualization {
    return {
        id: 3,
        task_id: 30,
        title: 'Busca no blog',
        source: 'Blog',
        status: 'updated',
        created_at: '2026-11-04T10:00:00Z',
        updated_at: '2026-11-04T10:02:00Z',
        closed_at: null,
        sections: [QUESTION],
        ...overrides,
    };
}

const animate = vi.fn();
const originalAnimate = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'animate');
const mounted: VueWrapper[] = [];

/** happy-dom has no layout: the center box before the flight, the compact corner box after it. */
function stubLayoutBoxes(): void {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        const corner = this.closest('[data-stage]')?.getAttribute('data-stage') === 'corner';
        const box = corner ? { left: 0, top: 0, width: 112, height: 112 } : { left: 500, top: 300, width: 440, height: 440 };

        return { ...box, x: box.left, y: box.top, right: box.left + box.width, bottom: box.top + box.height, toJSON: () => box } as DOMRect;
    });
}

async function mountHud(): Promise<{ wrapper: VueWrapper; router: Router }> {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/');
    await router.isReady();

    const wrapper = mount(App, { global: { plugins: [router], stubs: { transition: false } }, attachTo: document.body });
    mounted.push(wrapper);
    await flushPromises();
    useConnectionStore().markConnected();
    // THINKING is the frame with the most motion: rotations, irregular breath and particles.
    const assistant = useAssistantStore();
    assistant.wake();
    assistant.applyServerState('THINKING');
    await nextTick();
    expect(wrapper.get('[data-testid="jarvis-core"]').attributes('data-frame')).toBe('THINKING');

    return { wrapper, router };
}

/**
 * Leaving the `hud` route re-mounts the main slot outside the stage, so the
 * route transition only runs between regular pages: settle on `/settings`
 * first, then navigate to `/activity` and return the route wrapper mid-swap.
 */
async function navigateToActivity(wrapper: VueWrapper, router: Router) {
    await router.push('/settings');
    await flushPromises();
    await router.push('/activity');
    await nextTick();

    expect(router.currentRoute.value.name).toBe('activity');

    return wrapper.get('[data-testid="route-view"]');
}

function continuousCoreClasses(wrapper: VueWrapper): string[] {
    return wrapper
        .findAll('[data-testid="jarvis-core"] *, [data-testid="jarvis-core"]')
        .flatMap((element) => element.classes())
        .filter((name) => name.startsWith('core--animate-'));
}

function particles(wrapper: VueWrapper): number {
    return Number(wrapper.get('[data-testid="hud-stage-core"] canvas').attributes('data-particles'));
}

beforeEach(() => {
    animate.mockReset();
    Object.defineProperty(HTMLElement.prototype, 'animate', { configurable: true, writable: true, value: animate });
    mockFetch({
        'GET /api/v1/auth/me': { body: { data: makeUser() } },
        'POST /api/v1/visualizations/3/close': { body: { data: makeVisualization({ closed_at: '2026-11-04T10:05:00Z' }) } },
    });
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();

    if (originalAnimate === undefined) {
        delete (HTMLElement.prototype as Partial<HTMLElement>).animate;
    } else {
        Object.defineProperty(HTMLElement.prototype, 'animate', originalAnimate);
    }
});

describe('Reduced motion across the HUD (RNF-01, T24)', () => {
    it('runs the HUD, visualization and route change without motion under prefers-reduced-motion', async () => {
        mockViewport(1440, { reducedMotion: true });
        stubLayoutBoxes();
        const { wrapper, router } = await mountHud();
        const stage = () => wrapper.get('[data-testid="hud-stage"]');

        expect(router.currentRoute.value.name).toBe('hud');
        expect(stage().attributes('data-motion')).toBe('reduced');
        expect(wrapper.get('[data-testid="route-view"]').attributes('data-motion')).toBe('reduced');
        expect(continuousCoreClasses(wrapper)).toEqual([]);
        expect(particles(wrapper)).toBe(0);

        const visualizations = useVisualizationsStore();
        visualizations.apply(makeVisualization());
        await nextTick();
        await nextTick();

        expect(stage().attributes('data-stage')).toBe('corner');
        expect(animate).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="scan-sweep"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="stage-halo"]').exists()).toBe(false);
        expect(continuousCoreClasses(wrapper)).toEqual([]);
        expect(particles(wrapper)).toBe(0);

        visualizations.apply(makeVisualization({ sections: [QUESTION, NOTE] }));
        await nextTick();
        await nextTick();

        expect(wrapper.find('[data-testid="scan-bar"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="viz-section-note"] [data-testid="viz-text"]').text()).toBe('Pronto para revisar.');
        expect(wrapper.get('[data-testid="viz-section-note"] [data-testid="viz-text"]').attributes('data-typing')).not.toBe('true');

        await wrapper.get('[data-testid="viz-close"]').trigger('click');
        await flushPromises();

        expect(stage().attributes('data-stage')).toBe('center');
        expect(animate).not.toHaveBeenCalled();
        expect(continuousCoreClasses(wrapper)).toEqual([]);
        expect(particles(wrapper)).toBe(0);

        const view = await navigateToActivity(wrapper, router);
        expect(view.attributes('data-motion')).toBe('reduced');
        expect(view.element.children).toHaveLength(1);
        expect(view.findAll('.duration-\\[350ms\\]')).toHaveLength(0);
    });

    it('applies the same motion when it is allowed (control)', async () => {
        mockViewport(1440);
        stubLayoutBoxes();
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const { wrapper, router } = await mountHud();
        const stage = () => wrapper.get('[data-testid="hud-stage"]');

        expect(stage().attributes('data-motion')).toBe('full');
        expect(wrapper.get('[data-testid="route-view"]').attributes('data-motion')).toBe('full');
        expect(continuousCoreClasses(wrapper)).not.toEqual([]);
        expect(particles(wrapper)).toBeGreaterThan(0);

        const visualizations = useVisualizationsStore();
        visualizations.apply(makeVisualization());
        await nextTick();
        await nextTick();

        expect(stage().attributes('data-stage')).toBe('corner');
        expect(animate).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="scan-sweep"]').exists()).toBe(true);
        expect(continuousCoreClasses(wrapper)).not.toEqual([]);

        visualizations.apply(makeVisualization({ sections: [QUESTION, NOTE] }));
        await nextTick();

        expect(wrapper.find('[data-testid="scan-bar"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="viz-section-note"]').exists()).toBe(false);

        await vi.advanceTimersByTimeAsync(VISUALIZATION_SCAN_MS);

        expect(wrapper.get('[data-testid="viz-section-note"] [data-testid="viz-text"]').attributes('data-typing')).toBe('true');

        animate.mockReset();
        await wrapper.get('[data-testid="viz-close"]').trigger('click');
        await flushPromises();

        expect(stage().attributes('data-stage')).toBe('center');
        expect(animate).toHaveBeenCalledTimes(2);

        // Vue schedules the enter-to step on a real animation frame.
        vi.useRealTimers();
        const view = await navigateToActivity(wrapper, router);
        const [leaving, entering] = Array.from(view.element.children) as [Element, Element];
        expect(leaving.className).toEqual(expect.stringContaining('duration-[350ms]'));
        expect(entering.className).toEqual(expect.stringContaining('duration-[350ms]'));
        expect(entering.className).toEqual(expect.stringContaining('translate-y-4'));
    });
});
