import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import HudStage from '@/components/hud/HudStage.vue';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import VisualizationPanel from '@/components/hud/VisualizationPanel.vue';
import { HUD_STAGE_TRANSITION_MS } from '@/components/hud/constants';
import { useTaskTimelineStore } from '@/stores/taskTimeline';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';
import { useVoiceStore } from '@/stores/voice';
import type { TaskSummary, Visualization } from '@/types/models';
import { mockViewport } from '../helpers/matchMedia';

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
        sections: [{ key: 'question', position: 1, label: 'PERGUNTA', type: 'question', headline: 'Aula de inglês' }],
        ...overrides,
    };
}

function makeTask(overrides: Partial<TaskSummary> = {}): TaskSummary {
    return { id: '5', title: 'Pesquisar', status: 'running', progress: 10, ...overrides };
}

const animate = vi.fn();
const originalAnimate = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'animate');
const mounted: VueWrapper[] = [];

/** Center box before the flight, compact corner box after it (read from the stage state). */
function stubLayoutBoxes(): void {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        const corner = this.closest('[data-stage]')?.getAttribute('data-stage') === 'corner';
        const box = corner ? { left: 0, top: 0, width: 112, height: 112 } : { left: 500, top: 300, width: 440, height: 440 };

        return { ...box, x: box.left, y: box.top, right: box.left + box.width, bottom: box.top + box.height, toJSON: () => box } as DOMRect;
    });
}

function mountStage(): VueWrapper {
    const wrapper = mount(HudStage, { attachTo: document.body });
    mounted.push(wrapper);

    return wrapper;
}

async function openVisualization(): Promise<void> {
    useVisualizationsStore().apply(makeVisualization());
    await nextTick();
    await nextTick();
}

beforeEach(() => {
    animate.mockReset();
    Object.defineProperty(HTMLElement.prototype, 'animate', { configurable: true, writable: true, value: animate });
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.querySelectorAll('[data-test-extra]').forEach((element) => element.remove());
    vi.restoreAllMocks();

    if (originalAnimate === undefined) {
        delete (HTMLElement.prototype as Partial<HTMLElement>).animate;
    } else {
        Object.defineProperty(HTMLElement.prototype, 'animate', originalAnimate);
    }
});

describe('HudStage — focus mode and signature transition (UI-05, UI-06)', () => {
    it('renders the full core alone, without VISUALIZAÇÃO, when nothing is open', () => {
        mockViewport(1280);
        const wrapper = mountStage();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('center');
        expect(wrapper.getComponent(JarvisCore).props('size')).toBe('full');
        expect(wrapper.findComponent(VisualizationPanel).exists()).toBe(false);
        expect(wrapper.find('[data-testid="hud-stage-agents"]').exists()).toBe(false);
    });

    it('opening flies the core with two 700 ms effects: X on --ease-fly, Y on --ease-curve-y', async () => {
        mockViewport(1280);
        stubLayoutBoxes();
        const wrapper = mountStage();

        await openVisualization();

        expect(HUD_STAGE_TRANSITION_MS).toBe(700);
        expect(wrapper.getComponent(JarvisCore).props('size')).toBe('compact');
        expect(animate).toHaveBeenCalledTimes(2);

        const [xCall, yCall] = animate.mock.calls as [Keyframe[], KeyframeAnimationOptions][];
        const [xKeyframes, xTiming] = xCall ?? [[], {}];
        const [yKeyframes, yTiming] = yCall ?? [[], {}];
        expect(xTiming).toMatchObject({ duration: 700, easing: 'cubic-bezier(.2,.8,.2,1)' });
        expect(yTiming).toMatchObject({ duration: 700, easing: 'cubic-bezier(.6,0,.3,1)' });
        expect(xKeyframes[0]?.transform).toBe('translateX(500px)');
        expect(yKeyframes[0]?.transform).toBe(`translateY(300px) scale(${440 / 112})`);
        expect(animate.mock.contexts[0]).toBe(wrapper.get('[data-testid="hud-stage-core"]').element);
        expect(animate.mock.contexts[1]).toBe(wrapper.get('[data-testid="stage-core-flight"]').element);
        expect(wrapper.find('[data-testid="scan-sweep"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="stage-halo"]').exists()).toBe(true);
    });

    it('closing flies the core back and returns it to size="full"', async () => {
        mockViewport(1280);
        stubLayoutBoxes();
        const wrapper = mountStage();
        const visualizations = useVisualizationsStore();

        await openVisualization();
        animate.mockReset();

        visualizations.current = null;
        await nextTick();
        await nextTick();

        expect(wrapper.getComponent(JarvisCore).props('size')).toBe('full');
        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('center');
        expect(animate).toHaveBeenCalledTimes(2);
        expect(animate.mock.calls[0]?.[1]).toMatchObject({ duration: 700, easing: 'cubic-bezier(.2,.8,.2,1)' });
        expect(animate.mock.calls[1]?.[1]).toMatchObject({ duration: 700, easing: 'cubic-bezier(.6,0,.3,1)' });
    });

    it('applies the final state without animate, scan or halo under reduced motion (RNF-01)', async () => {
        mockViewport(1280, { reducedMotion: true });
        stubLayoutBoxes();
        const wrapper = mountStage();

        await openVisualization();

        expect(wrapper.get('[data-testid="hud-stage"]').attributes('data-stage')).toBe('corner');
        expect(wrapper.getComponent(JarvisCore).props('size')).toBe('compact');
        expect(animate).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="scan-sweep"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="stage-halo"]').exists()).toBe(false);
    });
});

describe('HudStage — Esc closes the visualization (RF-11)', () => {
    it('closes once on Escape outside editable fields', async () => {
        mockViewport(1280);
        mountStage();
        await openVisualization();
        const close = vi.spyOn(useVisualizationsStore(), 'close').mockResolvedValue(undefined);

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(close).toHaveBeenCalledTimes(1);
    });

    it('ignores Escape typed in a textarea', async () => {
        mockViewport(1280);
        mountStage();
        await openVisualization();
        const close = vi.spyOn(useVisualizationsStore(), 'close').mockResolvedValue(undefined);
        const textarea = document.createElement('textarea');
        textarea.setAttribute('data-test-extra', '');
        document.body.appendChild(textarea);
        textarea.focus();

        textarea.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(close).not.toHaveBeenCalled();
    });

    it('ignores Escape while a modal or drawer is open', async () => {
        mockViewport(1280);
        mountStage();
        await openVisualization();
        const close = vi.spyOn(useVisualizationsStore(), 'close').mockResolvedValue(undefined);
        const modal = document.createElement('div');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('data-test-extra', '');
        document.body.appendChild(modal);

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(close).not.toHaveBeenCalled();
    });

    it('does nothing without an open visualization', () => {
        mockViewport(1280);
        mountStage();
        const close = vi.spyOn(useVisualizationsStore(), 'close').mockResolvedValue(undefined);

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(close).not.toHaveBeenCalled();
    });
});

describe('HudStage — core inputs (UI-08, UI-21)', () => {
    it('passes waitingApproval while a task waits for a decision', async () => {
        mockViewport(1280);
        const wrapper = mountStage();
        const tasks = useTasksStore();

        expect(wrapper.getComponent(JarvisCore).props('waitingApproval')).toBe(false);

        tasks.upsert(makeTask({ status: 'waiting_approval' }));
        await nextTick();

        expect(wrapper.getComponent(JarvisCore).props('waitingApproval')).toBe(true);
    });

    it('passes the listening countdown as a 0–1 ratio of the window', async () => {
        mockViewport(1280);
        const wrapper = mountStage();
        const voice = useVoiceStore();

        voice.listeningWindowSeconds = 60;
        voice.countdownSeconds = 15;
        await nextTick();

        expect(wrapper.getComponent(JarvisCore).props('countdownRatio')).toBe(0.25);
        expect(wrapper.getComponent(JarvisCore).props('countdownSeconds')).toBe(15);
    });

    it('increments errorFlashKey once when a task snapshot turns failed', async () => {
        mockViewport(1280);
        const wrapper = mountStage();
        const timeline = useTaskTimelineStore();

        timeline.applyTask(makeTask({ status: 'running' }));
        await nextTick();
        expect(wrapper.getComponent(JarvisCore).props('errorFlashKey')).toBe(0);

        timeline.applyTask(makeTask({ status: 'failed', error: 'Falhou' }));
        await nextTick();
        expect(wrapper.getComponent(JarvisCore).props('errorFlashKey')).toBe(1);

        timeline.applyTask(makeTask({ status: 'failed', error: 'Falhou de novo' }));
        timeline.applyTask(makeTask({ id: '6', status: 'running' }));
        await nextTick();
        expect(wrapper.getComponent(JarvisCore).props('errorFlashKey')).toBe(1);
    });
});
