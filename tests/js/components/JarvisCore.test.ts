import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import {
    CORE_BAR_MAX_AMPLITUDE_COMPACT,
    CORE_BAR_MAX_AMPLITUDE_FULL,
    CORE_BAR_MIN_LENGTH,
    CORE_ERROR_FLASH_MS,
    CORE_METER_SEGMENTS,
    MAX_PARTICLES_COMPACT,
    MAX_PARTICLES_FULL,
} from '@/components/hud/constants';
import type { VoicePhase } from '@/stores/voice';
import type { AssistantState } from '@/types/models';

/** One input per frame of UI-07, with its pt-BR label and frame class. */
const FRAMES: ReadonlyArray<{
    frame: string;
    state: AssistantState;
    voicePhase: VoicePhase | null;
    label: string;
    className: string;
}> = [
    { frame: 'SLEEPING', state: 'IDLE', voicePhase: 'sleeping', label: 'Aguardando "Jarvis"', className: 'core--frame-sleeping' },
    { frame: 'LISTENING', state: 'IDLE', voicePhase: 'listening', label: 'OUVINDO', className: 'core--frame-listening' },
    {
        frame: 'LISTENING_SPEECH',
        state: 'IDLE',
        voicePhase: 'user_speaking',
        label: 'OUVINDO VOCÊ',
        className: 'core--frame-listening-speech',
    },
    { frame: 'SPEAKING', state: 'IDLE', voicePhase: 'speaking', label: 'FALANDO', className: 'core--frame-speaking' },
    { frame: 'THINKING', state: 'THINKING', voicePhase: 'listening', label: 'PENSANDO', className: 'core--frame-thinking' },
    { frame: 'EXECUTING', state: 'EXECUTING', voicePhase: 'sleeping', label: 'EXECUTANDO', className: 'core--frame-executing' },
    { frame: 'ERROR', state: 'ERROR', voicePhase: 'listening', label: 'ERRO', className: 'core--frame-error' },
    {
        frame: 'DISCONNECTED',
        state: 'DISCONNECTED',
        voicePhase: 'speaking',
        label: 'DESCONECTADO',
        className: 'core--frame-disconnected',
    },
];

const FRAME_CLASSES = FRAMES.map(({ className }) => className);

/** Classes of continuous rotation / motion (all start with `core--animate-`). */
const MOTION_CLASS = /core--animate-/;

const originalConcurrency = Object.getOwnPropertyDescriptor(window.navigator, 'hardwareConcurrency');

function particleCount(wrapper: VueWrapper): number {
    return Number(wrapper.get('canvas').attributes('data-particles'));
}

function matchMediaStub(reducedMotion: boolean): void {
    vi.spyOn(window, 'matchMedia').mockImplementation(
        (query) =>
            ({
                matches: reducedMotion && query.includes('prefers-reduced-motion'),
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            }) as unknown as MediaQueryList,
    );
}

function hardwareConcurrencyStub(value: number | undefined): void {
    Object.defineProperty(window.navigator, 'hardwareConcurrency', { configurable: true, get: () => value });
}

function label(wrapper: VueWrapper): string {
    return wrapper.get('[data-testid="core-label"]').text();
}

function litSegments(wrapper: VueWrapper, meter: 'mic' | 'output'): number {
    return wrapper
        .findAll(`[data-testid="core-meter-${meter}-segment"]`)
        .filter((segment) => segment.classes().includes('core__meter-segment--on')).length;
}

/** The scoped `<style>` of the component, for the RNF-07 animation guards. */
const componentStyle = (() => {
    const source = readFileSync(join(process.cwd(), 'resources/js/components/hud/JarvisCore.vue'), 'utf8');

    return source.slice(source.indexOf('<style scoped>'));
})();

function keyframeBody(name: string): string {
    const start = componentStyle.indexOf(`@keyframes ${name} {`);
    let depth = 0;

    for (let index = componentStyle.indexOf('{', start); index < componentStyle.length; index += 1) {
        if (componentStyle[index] === '{') {
            depth += 1;
        } else if (componentStyle[index] === '}') {
            depth -= 1;

            if (depth === 0) {
                return componentStyle.slice(start, index + 1);
            }
        }
    }

    return '';
}

beforeEach(() => {
    matchMediaStub(false);
});

afterEach(() => {
    vi.useRealTimers();

    if (originalConcurrency) {
        Object.defineProperty(window.navigator, 'hardwareConcurrency', originalConcurrency);
    } else {
        delete (window.navigator as unknown as Record<string, unknown>).hardwareConcurrency;
    }
});

describe('JarvisCore frames (UI-07)', () => {
    it.each(FRAMES)('renders $frame with its pt-BR label and frame class', ({ state, voicePhase, label: text, className }) => {
        const wrapper = mount(JarvisCore, { props: { state, voicePhase } });

        expect(wrapper.classes()).toContain(className);
        expect(label(wrapper)).toBe(text);

        for (const other of FRAME_CLASSES.filter((candidate) => candidate !== className)) {
            expect(wrapper.classes()).not.toContain(other);
        }

        wrapper.unmount();
    });

    it.each(FRAMES)('$frame with reduced motion has no rotation or particle classes', ({ state, voicePhase, className }) => {
        matchMediaStub(true);
        const wrapper = mount(JarvisCore, { props: { state, voicePhase, micLevel: 1, outputLevel: 1, waitingApproval: true } });

        expect(wrapper.classes()).toContain(className);
        expect(wrapper.classes()).toContain('core--reduced-motion');
        expect(wrapper.html()).not.toMatch(MOTION_CLASS);
        expect(wrapper.find('[data-testid="core-sonar"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="core-bars"]').exists()).toBe(false);
        expect(particleCount(wrapper)).toBe(0);

        wrapper.unmount();
    });

    it.each([
        ['THINKING', 'listening', 'PENSANDO', 'core--frame-thinking'],
        ['EXECUTING', 'sleeping', 'EXECUTANDO', 'core--frame-executing'],
        ['THINKING', 'user_speaking', 'OUVINDO VOCÊ', 'core--frame-listening-speech'],
        ['EXECUTING', 'speaking', 'FALANDO', 'core--frame-speaking'],
        ['IDLE', null, 'Voz indisponível', 'core--frame-sleeping'],
    ] as const)('resolves %s + %s to %s', (state, voicePhase, text, className) => {
        const wrapper = mount(JarvisCore, { props: { state, voicePhase } });

        expect(wrapper.classes()).toContain(className);
        expect(label(wrapper)).toBe(text);

        wrapper.unmount();
    });

    it('applies frame-specific visuals when motion is allowed', async () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'sleeping' } });

        expect(wrapper.get('[data-testid="core-ring-outer"]').classes()).toContain('core--animate-spin');
        expect(wrapper.get('[data-testid="core-nucleus"]').classes()).toContain('core--animate-breathe');
        expect(wrapper.find('[data-testid="core-halo"]').exists()).toBe(false);

        await wrapper.setProps({ voicePhase: 'speaking' });
        expect(wrapper.findAll('[data-testid="core-sonar"] circle')).toHaveLength(3);
        expect(wrapper.find('[data-testid="core-bars"]').exists()).toBe(true);

        await wrapper.setProps({ state: 'THINKING', voicePhase: 'listening' });
        expect(wrapper.findAll('[data-testid="core-orbit"]')).toHaveLength(2);
        expect(wrapper.get('[data-testid="core-nucleus"]').classes()).toContain('core--animate-irregular');

        await wrapper.setProps({ state: 'EXECUTING' });
        expect(wrapper.findAll('[data-testid="core-filament"]')).toHaveLength(3);
        expect(wrapper.get('[data-testid="core-filament"]').classes()).toContain('core--animate-flow');

        await wrapper.setProps({ state: 'DISCONNECTED' });
        expect(wrapper.find('[data-testid="core-disconnect"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="core-ring-outer"]').classes()).toContain('core--animate-glitch');

        wrapper.unmount();
    });

    it('keeps EXECUTING filaments solid and static with reduced motion', () => {
        matchMediaStub(true);
        const wrapper = mount(JarvisCore, { props: { state: 'EXECUTING', voicePhase: 'sleeping' } });

        for (const filament of wrapper.findAll('[data-testid="core-filament"]')) {
            expect(filament.classes()).toContain('core__filament--static');
        }

        wrapper.unmount();
    });

    it('drains the LISTENING arc by countdownRatio and shows the counter with reduced motion', async () => {
        const wrapper = mount(JarvisCore, {
            props: { state: 'IDLE', voicePhase: 'listening', countdownRatio: 0.5, countdownSeconds: 30 },
        });

        expect(wrapper.get('[data-testid="core-arc"]').attributes('stroke-dasharray')).toBe('528 1056');
        expect(wrapper.find('[data-testid="core-countdown"]').exists()).toBe(false);

        await wrapper.setProps({ voicePhase: 'user_speaking' });
        expect(wrapper.find('[data-testid="core-arc"]').exists()).toBe(false);
        wrapper.unmount();

        matchMediaStub(true);
        const reduced = mount(JarvisCore, {
            props: { state: 'IDLE', voicePhase: 'listening', countdownRatio: 0.5, countdownSeconds: 30 },
        });

        expect(reduced.find('[data-testid="core-arc"]').exists()).toBe(false);
        expect(reduced.get('[data-testid="core-countdown"]').text()).toBe('30');
        reduced.unmount();
    });

    it('turns the speech bars into a level ring with reduced motion', () => {
        matchMediaStub(true);
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'user_speaking', micLevel: 1 } });

        expect(wrapper.get('[data-testid="core-level-ring"]').attributes('opacity')).toBe('1');

        wrapper.unmount();
    });
});

describe('JarvisCore radial bars', () => {
    function lengths(wrapper: VueWrapper): Set<number> {
        return new Set(wrapper.findAll('[data-testid="core-bar"]').map((bar) => Number(bar.attributes('data-length'))));
    }

    it('sizes the operator speech bars as 3 + level × 64 px by the microphone', async () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'user_speaking', micLevel: 0, outputLevel: 1 } });

        expect(wrapper.findAll('[data-testid="core-bar"]')).toHaveLength(48);
        expect(lengths(wrapper)).toEqual(new Set([CORE_BAR_MIN_LENGTH]));

        await wrapper.setProps({ micLevel: 1 });
        expect(lengths(wrapper)).toEqual(new Set([CORE_BAR_MIN_LENGTH + CORE_BAR_MAX_AMPLITUDE_FULL]));

        await wrapper.setProps({ muted: true });
        expect(lengths(wrapper)).toEqual(new Set([CORE_BAR_MIN_LENGTH]));

        wrapper.unmount();
    });

    it('sizes the speaking bars by the output level and caps the compact amplitude at 16 px', async () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'speaking', micLevel: 1, outputLevel: 0 } });

        expect(lengths(wrapper)).toEqual(new Set([CORE_BAR_MIN_LENGTH]));

        await wrapper.setProps({ outputLevel: 1, size: 'compact' });
        expect(lengths(wrapper)).toEqual(new Set([CORE_BAR_MIN_LENGTH + CORE_BAR_MAX_AMPLITUDE_COMPACT]));

        wrapper.unmount();
    });
});

describe('JarvisCore approval ring (UI-08)', () => {
    it('toggles the amber ring with waitingApproval without changing the label', async () => {
        const wrapper = mount(JarvisCore, { props: { state: 'THINKING', voicePhase: 'listening' } });

        expect(wrapper.find('[data-testid="core-approval-ring"]').exists()).toBe(false);
        expect(label(wrapper)).toBe('PENSANDO');

        await wrapper.setProps({ waitingApproval: true });
        expect(wrapper.find('[data-testid="core-approval-ring"]').exists()).toBe(true);
        expect(wrapper.classes()).toContain('core--frame-thinking');
        expect(label(wrapper)).toBe('PENSANDO');

        await wrapper.setProps({ waitingApproval: false });
        expect(wrapper.find('[data-testid="core-approval-ring"]').exists()).toBe(false);

        wrapper.unmount();
    });
});

describe('JarvisCore meters (UI-09)', () => {
    it(`lights 6 of ${CORE_METER_SEGMENTS} MIC segments at micLevel 0.5`, () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening', micLevel: 0.5, outputLevel: 1.7 } });

        expect(wrapper.findAll('[data-testid="core-meter-mic-segment"]')).toHaveLength(12);
        expect(litSegments(wrapper, 'mic')).toBe(6);
        expect(litSegments(wrapper, 'output')).toBe(12);
        expect(wrapper.get('[data-testid="core-meter-mic"]').attributes('aria-valuenow')).toBe('0.5');
        expect(wrapper.get('[data-testid="core-meter-mic"]').text()).toContain('MIC');
        expect(wrapper.get('[data-testid="core-meter-output"]').text()).toContain('VOZ');

        wrapper.unmount();
    });

    it('reads MIC as 0 when muted', () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening', micLevel: 0.9, muted: true } });

        expect(litSegments(wrapper, 'mic')).toBe(0);
        expect(wrapper.get('[data-testid="core-meter-mic"]').attributes('aria-valuenow')).toBe('0');

        wrapper.unmount();
    });

    it('renders no meter in the compact core', () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening', micLevel: 0.5, size: 'compact' } });

        expect(wrapper.classes()).toContain('core--compact');
        expect(wrapper.find('[data-testid="core-meter-mic"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="core-meter-output"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('renders no meter without voice', () => {
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE' } });

        expect(wrapper.find('[data-testid="core-meter-mic"]').exists()).toBe(false);

        wrapper.unmount();
    });
});

describe('JarvisCore particles (RNF-07)', () => {
    it('caps particles at 120 in full and at 30 in compact', () => {
        const full = mount(JarvisCore, { props: { state: 'THINKING', size: 'full' } });
        expect(particleCount(full)).toBeGreaterThan(0);
        expect(particleCount(full)).toBeLessThanOrEqual(MAX_PARTICLES_FULL);
        full.unmount();

        const compact = mount(JarvisCore, { props: { state: 'THINKING', size: 'compact' } });
        expect(particleCount(compact)).toBeGreaterThan(0);
        expect(particleCount(compact)).toBeLessThanOrEqual(MAX_PARTICLES_COMPACT);
        compact.unmount();
    });

    it('halves the particle budget on devices with at most 4 cores', () => {
        hardwareConcurrencyStub(8);
        const strong = mount(JarvisCore, { props: { state: 'THINKING' } });
        const strongCount = particleCount(strong);
        strong.unmount();

        hardwareConcurrencyStub(4);
        const weak = mount(JarvisCore, { props: { state: 'THINKING' } });

        expect(particleCount(weak)).toBe(Math.round(strongCount / 2));

        weak.unmount();
    });

    it.each([
        ['SLEEPING', 'IDLE', 'sleeping'],
        ['DISCONNECTED', 'DISCONNECTED', 'listening'],
        ['ERROR', 'ERROR', 'listening'],
    ] as const)('runs no particles in %s', (_frame, state, voicePhase) => {
        const wrapper = mount(JarvisCore, { props: { state, voicePhase } });

        expect(particleCount(wrapper)).toBe(0);

        wrapper.unmount();
    });
});

describe('JarvisCore error flash (UI-07, UI-21)', () => {
    it('applies no infinite animation in ERROR', () => {
        const wrapper = mount(JarvisCore, { props: { state: 'ERROR', voicePhase: 'listening', waitingApproval: false } });

        expect(wrapper.html()).not.toMatch(MOTION_CLASS);
        expect(componentStyle).toMatch(/\.core--flash[^{]*\.core__segments\s*{\s*animation:[^;]*\b1;/);
        expect(componentStyle).toMatch(/\.core--flash[^{]*\.core__nucleus\s*{\s*animation:[^;]*\b1;/);

        wrapper.unmount();
    });

    it('runs the flash once when entering ERROR', async () => {
        vi.useFakeTimers();
        const wrapper = mount(JarvisCore, { props: { state: 'THINKING', voicePhase: 'listening' } });

        expect(wrapper.classes()).not.toContain('core--flash');

        await wrapper.setProps({ state: 'ERROR' });
        expect(wrapper.classes()).toContain('core--flash');

        vi.advanceTimersByTime(CORE_ERROR_FLASH_MS);
        await wrapper.vm.$nextTick();
        expect(wrapper.classes()).not.toContain('core--flash');

        vi.advanceTimersByTime(10_000);
        await wrapper.vm.$nextTick();
        expect(wrapper.classes()).not.toContain('core--flash');

        wrapper.unmount();
    });

    it('replays the flash once per errorFlashKey change without changing the frame', async () => {
        vi.useFakeTimers();
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening', errorFlashKey: 0 } });

        expect(wrapper.classes()).not.toContain('core--flash');

        await wrapper.setProps({ errorFlashKey: 1 });
        expect(wrapper.classes()).toContain('core--flash');
        expect(wrapper.classes()).toContain('core--frame-listening');

        vi.advanceTimersByTime(CORE_ERROR_FLASH_MS);
        await wrapper.vm.$nextTick();
        expect(wrapper.classes()).not.toContain('core--flash');

        vi.advanceTimersByTime(10_000);
        await wrapper.vm.$nextTick();
        expect(wrapper.classes()).not.toContain('core--flash');

        wrapper.unmount();
    });
});

describe('JarvisCore styles (RNF-07)', () => {
    it('keeps infinite animations on core--animate-* classes animating only transform, opacity or stroke-dash*', () => {
        const rules = [...componentStyle.matchAll(/([^{}]+)\{([^{}]*)\}/g)];
        const infinite = rules.filter(([, , body]) => /animation:[^;]*infinite/.test(body ?? ''));

        expect(infinite.length).toBeGreaterThan(0);

        for (const [, selector = '', body = ''] of infinite) {
            expect(selector, selector).toMatch(MOTION_CLASS);

            for (const declaration of body.match(/animation:[^;]*;/g) ?? []) {
                for (const name of declaration.match(/core-[a-z-]+/g) ?? []) {
                    const keyframes = keyframeBody(name);
                    const properties = [...keyframes.matchAll(/([a-z-]+)\s*:/g)].map(([, property = '']) => property);

                    expect(keyframes, name).not.toBe('');
                    expect(properties.every((property) => /^(transform|opacity|stroke-dashoffset|stroke-dasharray)$/.test(property)), name).toBe(true);
                }
            }
        }
    });

    it('left no legacy .core rules in app.css', () => {
        const css = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

        expect(css).not.toMatch(/\.core[-_\s{.]/);
        expect(css).not.toMatch(/@keyframes core-/);
    });
});
