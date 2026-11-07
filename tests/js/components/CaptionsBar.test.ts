import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CaptionsBar from '@/components/hud/CaptionsBar.vue';
import { useVoiceStore } from '@/stores/voice';

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

describe('CaptionsBar', () => {
    it('stays hidden without turns', () => {
        const wrapper = mount(CaptionsBar);

        expect(wrapper.find('[data-testid="captions-bar"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('updates the VOCÊ line with the partial transcription before the speech ends', async () => {
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setPhase('user_speaking');
        voice.setUserCaption('Quero saber');
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="captions-user"]').text()).toContain('VOCÊ');
        expect(wrapper.get('[data-testid="captions-user-text"]').text()).toBe('Quero saber');

        voice.setUserCaption('Quero saber da aula');
        await wrapper.vm.$nextTick();

        expect(voice.phase).toBe('user_speaking');
        expect(wrapper.get('[data-testid="captions-user-text"]').text()).toBe('Quero saber da aula');

        wrapper.unmount();
    });

    it('shows spoken words at full opacity and the following ones dimmed', async () => {
        reducedMotion(false);
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setAssistantText('');
        voice.appendAssistantText('Um program');
        voice.appendAssistantText('ador entra no bar');
        voice.setSpokenWordCount(2);
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="captions-assistant"]').text()).toContain('JARVIS');

        const words = wrapper.findAll('[data-testid="captions-word"]');
        expect(words.map((word) => word.text())).toEqual(['Um', 'programador', 'entra', 'no', 'bar']);
        expect(words.map((word) => word.attributes('data-spoken'))).toEqual(['true', 'true', 'false', 'false', 'false']);
        expect(words[1]?.classes()).toContain('opacity-100');
        expect(words[2]?.classes()).toContain('opacity-30');

        wrapper.unmount();
    });

    it('renders [risos] as a RISOS tag without brackets', async () => {
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setAssistantText('Um programador entra no bar [risos]');
        voice.setSpokenWordCount(6);
        await wrapper.vm.$nextTick();

        const line = wrapper.get('[data-testid="captions-assistant"]');
        expect(line.text()).toContain('Um');
        expect(line.text()).toContain('bar');
        expect(line.text()).not.toContain('[');
        expect(wrapper.get('[data-testid="captions-tag"]').text()).toBe('RISOS');

        wrapper.unmount();
    });

    it('renders markup as text, never as HTML', async () => {
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setAssistantText('<img src=x onerror=alert(1)>');
        voice.setUserCaption('<script>alert(1)</script>');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.get('[data-testid="captions-user-text"]').text()).toBe('<script>alert(1)</script>');

        wrapper.unmount();
    });

    it('reveals every word at once with prefers-reduced-motion', async () => {
        reducedMotion(true);
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setAssistantText('Pode falar agora');
        await wrapper.vm.$nextTick();

        const words = wrapper.findAll('[data-testid="captions-word"]');
        expect(words.every((word) => word.attributes('data-spoken') === 'true')).toBe(true);
        expect(wrapper.html()).not.toContain('transition-opacity');

        wrapper.unmount();
    });

    it('dims unspoken words to 30% opacity and colors the lines amber (VOCÊ) and cyan (JARVIS)', async () => {
        reducedMotion(false);
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setUserCaption('Quero saber');
        voice.setAssistantText('Um programador entra no bar');
        voice.setSpokenWordCount(1);
        await wrapper.vm.$nextTick();

        const words = wrapper.findAll('[data-testid="captions-word"]');
        expect(words.filter((word) => word.attributes('data-spoken') === 'false').every((word) => word.classes().includes('opacity-30'))).toBe(true);
        expect(words[0]?.classes()).not.toContain('opacity-30');
        expect(wrapper.get('[data-testid="captions-user-text"]').classes()).toContain('text-amber-400');
        expect(wrapper.get('[data-testid="captions-assistant"]').html()).toContain('text-cyan-300');
        expect(wrapper.get('[data-testid="captions-bar"]').classes()).toEqual(expect.arrayContaining(['text-caption', 'font-sans']));
        expect(wrapper.get('[data-testid="captions-bar"]').attributes('aria-live')).toBe('polite');

        wrapper.unmount();
    });

    it('renders the RISOS tag as a chamfered caps pill, never rounded', async () => {
        const wrapper = mount(CaptionsBar);
        const voice = useVoiceStore();

        voice.setAssistantText('Boa [risos]');
        voice.setSpokenWordCount(2);
        await wrapper.vm.$nextTick();

        const tag = wrapper.get('[data-testid="captions-tag"]');
        expect(tag.classes()).toEqual(expect.arrayContaining(['label-caps', 'chamfer-sm']));
        expect(tag.classes().some((name) => name.startsWith('rounded'))).toBe(false);

        wrapper.unmount();
    });

    it('blinks the VOCÊ caret while the operator speaks, static with reduced motion', async () => {
        reducedMotion(false);
        const voice = useVoiceStore();
        voice.setPhase('user_speaking');
        voice.setUserCaption('Quero');

        const animated = mount(CaptionsBar);
        expect(animated.get('[data-testid="captions-caret"]').classes()).toContain('captions__caret--blink');
        animated.unmount();

        vi.restoreAllMocks();
        reducedMotion(true);

        const still = mount(CaptionsBar);
        expect(still.get('[data-testid="captions-caret"]').classes()).not.toContain('captions__caret--blink');
        still.unmount();
    });
});
