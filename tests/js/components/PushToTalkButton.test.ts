import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import PushToTalkButton from '@/components/hud/PushToTalkButton.vue';
import { useVoiceStore, type VoicePhase } from '@/stores/voice';

/**
 * Mirrors `App.vue`: `wake` opens the window and `microphone` toggles the
 * mute, so each press/release reads the store as the real flow leaves it.
 */
function mountButton({ phase = 'sleeping', muted = false, keyboard = false }: { phase?: VoicePhase; muted?: boolean; keyboard?: boolean } = {}) {
    const voice = useVoiceStore();
    voice.setPhase(phase);
    voice.setMuted(muted);

    const wrapper = mount(PushToTalkButton, {
        attachTo: document.body,
        props: {
            keyboard,
            onWake: () => voice.setPhase('listening'),
            onMicrophone: () => voice.setMuted(!voice.muted),
        },
    });

    return { wrapper, voice, button: wrapper.get('[data-testid="push-to-talk"]') };
}

function pressSpace(type: 'keydown' | 'keyup', target: EventTarget = document, init: KeyboardEventInit = {}): KeyboardEvent {
    const event = new KeyboardEvent(type, { key: ' ', code: 'Space', bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(event);

    return event;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('PushToTalkButton', () => {
    it('renders the 84 px amber circle labelled "Segure para falar"', () => {
        const { button } = mountButton();

        expect(button.attributes('aria-label')).toBe('Segure para falar');
        expect(button.classes()).toEqual(expect.arrayContaining(['size-[84px]', 'border-amber-400', 'ptt']));
        expect(button.attributes('aria-disabled')).toBeUndefined();
    });

    it('wakes a sleeping window on pointerdown and leaves the microphone unmuted', async () => {
        const { wrapper, voice, button } = mountButton({ phase: 'sleeping' });

        await button.trigger('pointerdown');

        expect(wrapper.emitted('wake')).toHaveLength(1);
        expect(wrapper.emitted('microphone')).toBeUndefined();
        expect(voice.muted).toBe(false);
        expect(button.attributes('data-pressed')).toBe('true');
    });

    it('wakes and unmutes a sleeping window that was muted', async () => {
        const { wrapper, voice, button } = mountButton({ phase: 'sleeping', muted: true });

        await button.trigger('pointerdown');

        expect(wrapper.emitted('wake')).toHaveLength(1);
        expect(wrapper.emitted('microphone')).toHaveLength(1);
        expect(voice.muted).toBe(false);
    });

    it.each(['pointerup', 'pointercancel'])('mutes on %s without sleeping', async (release) => {
        const { wrapper, voice, button } = mountButton({ phase: 'sleeping' });

        await button.trigger('pointerdown');
        await button.trigger(release);

        expect(wrapper.emitted('microphone')).toHaveLength(1);
        expect(wrapper.emitted('sleep')).toBeUndefined();
        expect(voice.muted).toBe(true);
        expect(voice.phase).toBe('listening');
        expect(button.attributes('data-pressed')).toBe('false');
    });

    it('only unmutes while JARVIS speaks, never interrupting it', async () => {
        const { wrapper, voice, button } = mountButton({ phase: 'speaking', muted: true });

        await button.trigger('pointerdown');

        expect(wrapper.emitted('microphone')).toHaveLength(1);
        expect(wrapper.emitted('wake')).toBeUndefined();
        expect(Object.keys(wrapper.emitted())).not.toContain('sleep');
        expect(voice.phase).toBe('speaking');
        expect(voice.muted).toBe(false);
    });

    it('does nothing on press in an open, unmuted window', async () => {
        const { wrapper, button } = mountButton({ phase: 'listening' });

        await button.trigger('pointerdown');

        expect(wrapper.emitted('wake')).toBeUndefined();
        expect(wrapper.emitted('microphone')).toBeUndefined();
    });

    it('ignores a release without a press', async () => {
        const { wrapper, button } = mountButton({ phase: 'listening' });

        await button.trigger('pointerup');

        expect(wrapper.emitted('microphone')).toBeUndefined();
    });

    it('treats Space keydown/keyup on the document as pointerdown/pointerup', () => {
        const { wrapper, voice } = mountButton({ phase: 'sleeping', keyboard: true });

        const down = pressSpace('keydown');
        expect(down.defaultPrevented).toBe(true);
        expect(wrapper.emitted('wake')).toHaveLength(1);
        expect(wrapper.emitted('microphone')).toBeUndefined();

        const up = pressSpace('keyup');
        expect(up.defaultPrevented).toBe(true);
        expect(wrapper.emitted('microphone')).toHaveLength(1);
        expect(wrapper.emitted('sleep')).toBeUndefined();
        expect(voice.muted).toBe(true);
    });

    it('ignores the Space auto-repeat', () => {
        const { wrapper } = mountButton({ phase: 'listening', muted: true, keyboard: true });

        pressSpace('keydown');
        pressSpace('keydown', document, { repeat: true });
        pressSpace('keydown', document, { repeat: true });

        expect(wrapper.emitted('microphone')).toHaveLength(1);
    });

    it.each(['input', 'textarea', 'select'])('ignores Space while a %s has focus', (tag) => {
        const { wrapper } = mountButton({ phase: 'sleeping', keyboard: true });
        const field = document.createElement(tag);
        document.body.appendChild(field);
        field.focus();

        const down = pressSpace('keydown', field);
        pressSpace('keyup', field);

        expect(down.defaultPrevented).toBe(false);
        expect(wrapper.emitted('wake')).toBeUndefined();
        expect(wrapper.emitted('microphone')).toBeUndefined();
    });

    it('ignores Space inside a contenteditable region', () => {
        const { wrapper } = mountButton({ phase: 'sleeping', keyboard: true });
        const editor = document.createElement('div');
        editor.setAttribute('contenteditable', 'true');
        editor.innerHTML = '<span>texto</span>';
        document.body.appendChild(editor);

        pressSpace('keydown', editor.firstElementChild!);

        expect(wrapper.emitted('wake')).toBeUndefined();
    });

    it('does not bind Space without the keyboard option', () => {
        const { wrapper } = mountButton({ phase: 'sleeping' });

        pressSpace('keydown');

        expect(wrapper.emitted('wake')).toBeUndefined();
    });

    it('stops listening to Space after unmount', () => {
        const { wrapper } = mountButton({ phase: 'sleeping', keyboard: true });
        const emitted = wrapper.emitted();

        wrapper.unmount();
        pressSpace('keydown');

        expect(emitted.wake).toBeUndefined();
    });

    it('is disabled and inert while voice is unavailable', async () => {
        const voice = useVoiceStore();
        voice.markUnavailable('mic_denied');
        const { wrapper, button } = mountButton({ phase: 'sleeping', muted: true, keyboard: true });

        expect(button.attributes('aria-disabled')).toBe('true');

        await button.trigger('pointerdown');
        await button.trigger('pointerup');
        pressSpace('keydown');
        pressSpace('keyup');

        expect(wrapper.emitted('wake')).toBeUndefined();
        expect(wrapper.emitted('microphone')).toBeUndefined();
    });
});
