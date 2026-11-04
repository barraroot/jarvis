import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import { CORE_BAR_MAX_LENGTH, CORE_BAR_MIN_LENGTH, MAX_PARTICLES_COMPACT, MAX_PARTICLES_FULL } from '@/components/hud/constants';

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

    describe('voice phases (UI-05, UI-06)', () => {
        const VOICE = [
            ['listening', 'core--voice-listening', 'OUVINDO'],
            ['user_speaking', 'core--voice-user-speaking', 'OUVINDO VOCÊ'],
            ['speaking', 'core--voice-speaking', 'FALANDO'],
            ['sleeping', 'core--voice-sleeping', 'Aguardando "Jarvis"'],
        ] as const;

        it.each(VOICE)('applies a distinct class and the exact label for %s', (voicePhase, className, label) => {
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase } });

            expect(wrapper.classes()).toContain(className);
            expect(wrapper.get('[data-testid="core-label"]').text()).toBe(label);

            for (const [, other] of VOICE.filter(([phase]) => phase !== voicePhase)) {
                expect(wrapper.classes()).not.toContain(other);
            }

            wrapper.unmount();
        });

        it('keeps the status labels of faults over the voice phase', () => {
            const wrapper = mount(JarvisCore, { props: { state: 'ERROR', voicePhase: 'listening' } });

            expect(wrapper.get('[data-testid="core-label"]').text()).toBe('Erro');
            expect(wrapper.classes()).not.toContain('core--voice-listening');

            wrapper.unmount();
        });

        it('draws dotted rings while listening and radial bars only while someone speaks', async () => {
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening' } });

            expect(wrapper.find('[data-testid="core-dots"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="core-bars"]').exists()).toBe(false);

            await wrapper.setProps({ voicePhase: 'user_speaking' });
            expect(wrapper.find('[data-testid="core-dots"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="core-bars"]').exists()).toBe(true);

            wrapper.unmount();
        });

        it('sizes the user-speaking bars by the microphone level, minimum at 0 and maximum at 1', async () => {
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'user_speaking', micLevel: 0, outputLevel: 1 } });
            const lengths = () => wrapper.findAll('[data-testid="core-bar"]').map((bar) => Number(bar.attributes('data-length')));

            expect(lengths().length).toBeGreaterThan(0);
            expect(new Set(lengths())).toEqual(new Set([CORE_BAR_MIN_LENGTH]));

            await wrapper.setProps({ micLevel: 1 });
            expect(new Set(lengths())).toEqual(new Set([CORE_BAR_MAX_LENGTH]));

            wrapper.unmount();
        });

        it('sizes the speaking bars by the output level', async () => {
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'speaking', micLevel: 1, outputLevel: 0 } });
            const first = () => Number(wrapper.get('[data-testid="core-bar"]').attributes('data-length'));

            expect(first()).toBe(CORE_BAR_MIN_LENGTH);

            await wrapper.setProps({ outputLevel: 1 });
            expect(first()).toBe(CORE_BAR_MAX_LENGTH);

            wrapper.unmount();
        });

        it('reflects MIC and VOZ levels 0–1 on the side meters and reads MIC as 0 when muted', async () => {
            const wrapper = mount(JarvisCore, {
                props: { state: 'IDLE', voicePhase: 'listening', micLevel: 0.5, outputLevel: 1.7 },
            });

            expect(wrapper.get('[data-testid="core-meter-mic"]').attributes('aria-valuenow')).toBe('0.5');
            expect(wrapper.get('[data-testid="core-meter-mic-fill"]').attributes('style')).toContain('height: 50%');
            expect(wrapper.get('[data-testid="core-meter-output"]').attributes('aria-valuenow')).toBe('1');
            expect(wrapper.get('[data-testid="core-meter-output"]').text()).toContain('VOZ');
            expect(wrapper.get('[data-testid="core-meter-mic"]').text()).toContain('MIC');

            await wrapper.setProps({ muted: true, voicePhase: 'user_speaking' });
            expect(wrapper.get('[data-testid="core-meter-mic"]').attributes('aria-valuenow')).toBe('0');
            expect(Number(wrapper.get('[data-testid="core-bar"]').attributes('data-length'))).toBe(CORE_BAR_MIN_LENGTH);

            wrapper.unmount();
        });

        it('has no meters without a voice phase', () => {
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE' } });

            expect(wrapper.find('[data-testid="core-meter-mic"]').exists()).toBe(false);

            wrapper.unmount();
        });

        it('applies no animation class with prefers-reduced-motion in any voice phase', async () => {
            matchMediaStub(true);
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening', micLevel: 1 } });

            expect(wrapper.html()).not.toMatch(/core--animate|core__bars--animated/);

            for (const voicePhase of ['user_speaking', 'speaking', 'sleeping'] as const) {
                await wrapper.setProps({ voicePhase });
                expect(wrapper.html(), voicePhase).not.toMatch(/core--animate|core__bars--animated/);
            }

            wrapper.unmount();
            vi.restoreAllMocks();
        });

        it('animates the dotted rings and bars when motion is allowed', async () => {
            matchMediaStub(false);
            const wrapper = mount(JarvisCore, { props: { state: 'IDLE', voicePhase: 'listening' } });

            expect(wrapper.get('[data-testid="core-dots"]').classes()).toContain('core--animate-dots');

            await wrapper.setProps({ voicePhase: 'speaking' });
            expect(wrapper.get('[data-testid="core-bars"]').classes()).toContain('core__bars--animated');

            wrapper.unmount();
            vi.restoreAllMocks();
        });
    });
});
