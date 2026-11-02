import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import { MAX_PARTICLES_COMPACT, MAX_PARTICLES_FULL } from '@/components/hud/constants';

const STATES = ['IDLE', 'LISTENING', 'THINKING', 'SPEAKING', 'EXECUTING', 'SLEEPING', 'ERROR', 'DISCONNECTED'] as const;

const LABELS: Record<(typeof STATES)[number], string> = {
    IDLE: 'Ocioso',
    LISTENING: 'Ouvindo',
    THINKING: 'Pensando',
    SPEAKING: 'Falando',
    EXECUTING: 'Executando',
    SLEEPING: 'Dormindo',
    ERROR: 'Erro',
    DISCONNECTED: 'Desconectado',
};

const originalConcurrency = Object.getOwnPropertyDescriptor(window.navigator, 'hardwareConcurrency');

afterEach(() => {
    if (originalConcurrency) {
        Object.defineProperty(window.navigator, 'hardwareConcurrency', originalConcurrency);
    } else {
        delete (window.navigator as unknown as Record<string, unknown>).hardwareConcurrency;
    }
});

function particleCount(wrapper: ReturnType<typeof mount>): number {
    return Number(wrapper.get('canvas').attributes('data-particles'));
}

function matchMediaStub(reducedMotion: boolean) {
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

function hardwareConcurrencyStub(value: number | undefined) {
    Object.defineProperty(window.navigator, 'hardwareConcurrency', { configurable: true, get: () => value });
}

describe('JarvisCore', () => {
    it.each(STATES)('renders state %s with its class and i18n label', (state) => {
        const wrapper = mount(JarvisCore, { props: { state } });

        expect(wrapper.classes()).toContain(`core--${state.toLowerCase()}`);
        expect(wrapper.get('[data-testid="core-label"]').text()).toBe(LABELS[state]);

        wrapper.unmount();
    });

    it('caps particles at 120 in full and at 30 in compact', () => {
        const full = mount(JarvisCore, { props: { state: 'IDLE', size: 'full' } });
        const fullCount = particleCount(full);
        expect(fullCount).toBeGreaterThan(0);
        expect(fullCount).toBeLessThanOrEqual(MAX_PARTICLES_FULL);
        full.unmount();

        const compact = mount(JarvisCore, { props: { state: 'IDLE', size: 'compact' } });
        const compactCount = particleCount(compact);
        expect(compactCount).toBeGreaterThan(0);
        expect(compactCount).toBeLessThanOrEqual(MAX_PARTICLES_COMPACT);
        compact.unmount();
    });

    it('runs no particles and drops intensity while SLEEPING', () => {
        const wrapper = mount(JarvisCore, { props: { state: 'SLEEPING' } });

        expect(particleCount(wrapper)).toBe(0);
        expect(wrapper.classes()).toContain('core--sleeping');

        wrapper.unmount();
    });

    it('halves the particle budget on devices with at most 4 cores', () => {
        hardwareConcurrencyStub(4);
        const wrapper = mount(JarvisCore, { props: { state: 'IDLE', size: 'full' } });

        expect(particleCount(wrapper)).toBeLessThanOrEqual(Math.round(96 / 2));
        expect(particleCount(wrapper)).toBeLessThanOrEqual(MAX_PARTICLES_FULL);

        wrapper.unmount();
    });

    it('removes animation classes and particles with prefers-reduced-motion', () => {
        matchMediaStub(true);
        const wrapper = mount(JarvisCore, { props: { state: 'THINKING' } });

        expect(wrapper.classes()).toContain('core--reduced-motion');
        expect(wrapper.findAll('[class*="core--animate"]')).toHaveLength(0);
        expect(particleCount(wrapper)).toBe(0);

        wrapper.unmount();
    });

    it('keeps animations and particles when motion is allowed', () => {
        matchMediaStub(false);
        const wrapper = mount(JarvisCore, { props: { state: 'THINKING' } });

        expect(wrapper.classes()).not.toContain('core--reduced-motion');
        expect(wrapper.find('[class*="core--animate"]').exists()).toBe(true);
        expect(particleCount(wrapper)).toBeGreaterThan(0);

        wrapper.unmount();
    });

    it('renders the amber sweep arc only while EXECUTING', () => {
        expect(mount(JarvisCore, { props: { state: 'IDLE' } }).find('[data-testid="core-sweep"]').exists()).toBe(false);

        const executing = mount(JarvisCore, { props: { state: 'EXECUTING' } });
        expect(executing.find('[data-testid="core-sweep"]').exists()).toBe(true);
        executing.unmount();
    });

    it('renders the broken-link glyph in DISCONNECTED and no particles', () => {
        const wrapper = mount(JarvisCore, { props: { state: 'DISCONNECTED' } });

        expect(wrapper.find('[data-testid="core-disconnect"]').exists()).toBe(true);
        expect(particleCount(wrapper)).toBe(0);

        wrapper.unmount();
    });

    it('contracts the rings while LISTENING as the level rises', async () => {
        const wrapper = mount(JarvisCore, { props: { state: 'LISTENING', level: 0.9 } });

        expect(wrapper.get('[data-testid="core-scale"]').attributes('style')).toContain('scale(0.946)');

        await wrapper.setProps({ state: 'SPEAKING' });
        expect(wrapper.get('[data-testid="core-scale"]').attributes('style')).toContain('scale(1.045)');

        wrapper.unmount();
    });
});
