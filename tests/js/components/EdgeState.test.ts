import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EdgeState, { type EdgeStateTone } from '@/components/ui/EdgeState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudSheet from '@/components/ui/HudSheet.vue';
import { mockViewport } from '../helpers/matchMedia';

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('EmptyState', () => {
    it('renders the title, the text and the next-step action', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'Nenhuma conversa ainda', text: 'Diga "Jarvis" ou escreva abaixo.' },
            slots: { action: '<button type="button">Começar</button>' },
        });

        expect(wrapper.get('[data-testid="empty-state-title"]').text()).toBe('Nenhuma conversa ainda');
        expect(wrapper.get('[data-testid="empty-state-text"]').text()).toBe('Diga "Jarvis" ou escreva abaixo.');
        expect(wrapper.get('[data-testid="empty-state-action"] button').text()).toBe('Começar');
        expect(wrapper.classes()).toEqual(expect.arrayContaining(['glass', 'chamfer-md']));
    });

    it('keeps the compact message form', () => {
        const wrapper = mount(EmptyState, { props: { message: 'nada aqui' } });

        expect(wrapper.text()).toBe('nada aqui');
        expect(wrapper.find('[data-testid="empty-state-title"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="empty-state-action"]').exists()).toBe(false);
    });
});

describe('EdgeState', () => {
    it.each<[EdgeStateTone, string, string]>([
        ['offline', 'text-danger-400', 'status'],
        ['cost-limit', 'text-amber-400', 'status'],
        ['voice', 'text-amber-400', 'status'],
        ['error', 'text-danger-400', 'alert'],
    ])('applies the %s tone with its status color', (tone, color, role) => {
        const wrapper = mount(EdgeState, {
            props: { tone, title: 'Título', text: 'Texto' },
            slots: { actions: '<button type="button">Ação</button>' },
        });

        expect(wrapper.classes()).toEqual(expect.arrayContaining([`edge-state--${tone}`, 'glass', 'chamfer-md']));
        expect(wrapper.attributes('data-tone')).toBe(tone);
        expect(wrapper.attributes('role')).toBe(role);
        expect(wrapper.get('[data-testid="edge-state-title"]').classes()).toContain(color);
        expect(wrapper.get('[data-testid="edge-state-text"]').text()).toBe('Texto');
        expect(wrapper.get('[data-testid="edge-state-actions"]').text()).toBe('Ação');
    });
});

describe('HudSheet', () => {
    function mountSheet(props: Record<string, unknown> = {}) {
        const wrapper = mount(HudSheet, {
            attachTo: document.body,
            props: {
                open: false,
                expanded: false,
                label: 'Agentes',
                'onUpdate:open': (value: boolean) => wrapper.setProps({ open: value }),
                'onUpdate:expanded': (value: boolean) => wrapper.setProps({ expanded: value }),
                ...props,
            },
            slots: { default: '<p>conteúdo</p>' },
            global: { stubs: { transition: false } },
        });

        return wrapper;
    }

    it('opens and closes by prop', async () => {
        mockViewport(390, { reducedMotion: true });
        const wrapper = mountSheet();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

        await wrapper.setProps({ open: true });
        const dialog = wrapper.get('[role="dialog"]');
        expect(dialog.attributes('aria-label')).toBe('Agentes');
        expect(dialog.text()).toContain('conteúdo');
        expect(dialog.classes()).toEqual(expect.arrayContaining(['glass', 'chamfer-md']));

        await wrapper.setProps({ open: false });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('closes on Escape', async () => {
        mockViewport(390, { reducedMotion: true });
        const wrapper = mountSheet({ open: true });

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(wrapper.props('open')).toBe(false);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('toggles the height with a 44 px handle', async () => {
        mockViewport(390, { reducedMotion: true });
        const wrapper = mountSheet({ open: true });
        const handle = wrapper.get('[data-testid="sheet-handle"]');

        expect(handle.classes()).toContain('min-h-11');
        expect(handle.attributes('aria-expanded')).toBe('false');
        expect(handle.attributes('aria-label')).toBe('Expandir painel');

        await handle.trigger('click');

        expect(wrapper.props('expanded')).toBe(true);
        expect(wrapper.get('[role="dialog"]').attributes('data-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="sheet-handle"]').attributes('aria-label')).toBe('Recolher painel');
    });

    it('slides up with transition classes when motion is allowed', async () => {
        mockViewport(390);
        const wrapper = mountSheet();

        await wrapper.setProps({ open: true });

        expect(wrapper.get('[role="dialog"]').classes()).toEqual(
            expect.arrayContaining(['transition-transform', 'translate-y-full']),
        );
    });

    it('applies no transition class with reduced motion', async () => {
        mockViewport(390, { reducedMotion: true });
        const wrapper = mountSheet();

        await wrapper.setProps({ open: true });

        const classes = wrapper.get('[role="dialog"]').classes();
        expect(classes.filter((name) => name.startsWith('transition') || name.startsWith('translate'))).toEqual([]);
    });
});
