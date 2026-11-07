import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import Clock from '@/components/ui/Clock.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudModal from '@/components/ui/HudModal.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudSelect from '@/components/ui/HudSelect.vue';
import HudTextarea from '@/components/ui/HudTextarea.vue';
import HudToast from '@/components/ui/HudToast.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Waveform from '@/components/ui/Waveform.vue';
import { notificationToastVariant } from '@/composables/useNotificationEvents';
import { useToastStore, type ToastVariant } from '@/stores/toast';

describe('HudPanel', () => {
    it('renders the uppercase spaced title, decorated corners and slot', () => {
        const wrapper = mount(HudPanel, { props: { title: 'Sistema' }, slots: { default: '<p>conteudo</p>' } });

        const title = wrapper.get('h2');
        expect(title.text()).toBe('Sistema');
        expect(title.classes()).toContain('label-caps');
        expect(wrapper.classes()).toEqual(expect.arrayContaining(['glass', 'chamfer-md', 'corner-light']));
        expect(wrapper.html()).toContain('<p>conteudo</p>');
    });
});

describe('HudButton', () => {
    it.each(['primary', 'ghost', 'danger'] as const)('renders the %s variant', (variant) => {
        const wrapper = mount(HudButton, { props: { variant }, slots: { default: 'Ok' } });

        expect(wrapper.attributes('data-variant')).toBe(variant);
        expect(wrapper.text()).toBe('Ok');
    });

    it('disables itself and shows a spinner while loading', () => {
        const wrapper = mount(HudButton, { props: { loading: true }, slots: { default: 'Ok' } });

        expect(wrapper.attributes('disabled')).toBeDefined();
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(wrapper.find('[role="status"]').exists()).toBe(true);
    });
});

describe('HudInput / HudTextarea', () => {
    it('binds v-model and shows the error', async () => {
        const wrapper = mount(HudInput, {
            props: { label: 'E-mail', modelValue: 'a', error: 'Inválido', 'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }) },
        });

        await wrapper.get('input').setValue('tony@example.com');

        expect(wrapper.props('modelValue')).toBe('tony@example.com');
        expect(wrapper.get('label').text()).toBe('E-mail');
        expect(wrapper.get('[role="alert"]').text()).toBe('Inválido');
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    });

    it('textarea binds v-model', async () => {
        const wrapper = mount(HudTextarea, {
            props: { label: 'Mensagem', modelValue: '', 'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }) },
        });

        await wrapper.get('textarea').setValue('olá');

        expect(wrapper.props('modelValue')).toBe('olá');
    });
});

describe('HudToggle', () => {
    it('toggles the boolean model', async () => {
        const wrapper = mount(HudToggle, {
            props: { label: 'Wake word', modelValue: false, 'onUpdate:modelValue': (value: boolean) => wrapper.setProps({ modelValue: value }) },
        });

        expect(wrapper.attributes('aria-checked')).toBe('false');
        await wrapper.trigger('click');
        expect(wrapper.props('modelValue')).toBe(true);
        expect(wrapper.attributes('aria-checked')).toBe('true');
    });
});

describe('HudSelect', () => {
    it('renders options with a translated placeholder', async () => {
        const wrapper = mount(HudSelect, {
            props: {
                options: [
                    { value: 'a', label: 'Alfa' },
                    { value: 'b', label: 'Beta' },
                ],
                modelValue: '',
                'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }),
            },
        });

        const options = wrapper.findAll('option');
        expect(options.map((option) => option.text())).toEqual(['selecione', 'Alfa', 'Beta']);

        await wrapper.get('select').setValue('b');
        expect(wrapper.props('modelValue')).toBe('b');
    });
});

describe('StatusBadge', () => {
    it.each([
        ['IDLE', 'Ocioso', 'cyan'],
        ['LISTENING', 'Ouvindo', 'blue'],
        ['THINKING', 'Pensando', 'purple'],
        ['SPEAKING', 'Falando', 'cyan'],
        ['EXECUTING', 'Executando', 'amber'],
        ['SLEEPING', 'Dormindo', 'muted'],
        ['ERROR', 'Erro', 'danger'],
        ['DISCONNECTED', 'Desconectado', 'danger'],
        ['pending', 'Pendente', 'muted'],
        ['queued', 'Na fila', 'blue'],
        ['running', 'Em execução', 'cyan'],
        ['Waiting approval', 'Aguardando aprovação', 'amber'],
        ['completed', 'Concluída', 'cyan'],
        ['failed', 'Falhou', 'danger'],
        ['Cancelled', 'Cancelada', 'muted'],
        ['whatever', 'Desconhecido', 'muted'],
    ])('maps %s to "%s" with %s tone', (status, label, tone) => {
        const wrapper = mount(StatusBadge, { props: { status } });

        expect(wrapper.text()).toBe(label);
        expect(wrapper.attributes('data-tone')).toBe(tone);
    });
});

describe('ProgressBar', () => {
    it('clamps the value and renders the variant', () => {
        const wrapper = mount(ProgressBar, { props: { value: 150, label: 'CPU', variant: 'amber' } });

        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('100');
        expect(wrapper.get('[data-testid="progress-fill"]').classes()).toContain('bg-amber-400');
        expect(wrapper.text()).toContain('100%');
    });

    it('computes percent from max', () => {
        const wrapper = mount(ProgressBar, { props: { value: 5, max: 20 } });

        expect(wrapper.get('[data-testid="progress-fill"]').attributes('style')).toContain('width: 25%');
    });
});

describe('HudModal', () => {
    it('renders only when open and closes on backdrop and Escape', async () => {
        const wrapper = mount(HudModal, {
            props: { open: true, title: 'Confirmar', 'onUpdate:open': (value: boolean) => wrapper.setProps({ open: value }) },
            slots: { default: 'Corpo' },
        });

        expect(wrapper.get('[role="dialog"]').text()).toContain('Corpo');

        await wrapper.get('[data-testid="hud-modal-backdrop"]').trigger('click');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

        await wrapper.setProps({ open: true });
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(wrapper.props('open')).toBe(false);
    });
});

describe('HudToast', () => {
    it('renders toasts from the store and dismisses them', async () => {
        const toasts = useToastStore();
        toasts.push('Muitas requisições', { variant: 'warning', timeoutMs: 0 });

        const wrapper = mount(HudToast);
        await nextTick();

        expect(wrapper.text()).toContain('Muitas requisições');
        expect(wrapper.find('[data-variant="warning"]').exists()).toBe(true);

        await wrapper.get('button').trigger('click');
        expect(toasts.toasts).toHaveLength(0);
    });
});

describe('EmptyState', () => {
    it('shows "sem dados" by default and never simulated values', () => {
        expect(mount(EmptyState).text()).toBe('sem dados');
        expect(mount(EmptyState, { props: { message: 'nada aqui' } }).text()).toBe('nada aqui');
    });
});

describe('Waveform', () => {
    it('bars react to the level', async () => {
        const wrapper = mount(Waveform, { props: { level: 0, bars: 9 } });
        const quietHeights = wrapper.findAll('[data-testid="waveform-bar"]').map((bar) => bar.attributes('style'));

        expect(quietHeights).toHaveLength(9);

        await wrapper.setProps({ level: 1 });
        const loudBars = wrapper.findAll('[data-testid="waveform-bar"]');

        expect(loudBars[4]?.attributes('style')).toContain('height: 100%');
        expect(loudBars.map((bar) => bar.attributes('style'))).not.toEqual(quietHeights);
        expect(wrapper.attributes('aria-valuenow')).toBe('100');
    });
});

describe('Clock', () => {
    it('renders hh:mm:ss with date and ticks every second', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 5, 9, 19, 33, 3));

        const wrapper = mount(Clock);

        expect(wrapper.get('[data-testid="clock-time"]').text()).toBe('19:33:03');
        expect(wrapper.get('[data-testid="clock-date"]').text()).toContain('2026');

        vi.advanceTimersByTime(1000);
        await nextTick();

        expect(wrapper.get('[data-testid="clock-time"]').text()).toBe('19:33:04');
        wrapper.unmount();
    });
});

describe('chamfered primitives (UI-01)', () => {
    const options = [{ value: 'a', label: 'Alfa' }];

    it.each([
        ['HudPanel', () => mount(HudPanel, { props: { title: 'Painel' } }), 'chamfer-md'],
        ['HudModal', () => mount(HudModal, { props: { open: true, title: 'Modal' } }).get('[role="dialog"]'), 'chamfer-md'],
        ['HudButton', () => mount(HudButton, { slots: { default: 'Ok' } }), 'chamfer-sm'],
        ['HudInput', () => mount(HudInput).get('input'), 'chamfer-sm'],
        ['HudSelect', () => mount(HudSelect, { props: { options } }).get('select'), 'chamfer-sm'],
        ['HudTextarea', () => mount(HudTextarea).get('textarea'), 'chamfer-sm'],
        ['HudToggle', () => mount(HudToggle, { props: { label: 'Ligar' } }).get('[role="switch"] > span'), 'chamfer-sm'],
        ['StatusBadge', () => mount(StatusBadge, { props: { status: 'running' } }), 'chamfer-sm'],
    ])('%s applies %s', (_name, render, chamfer) => {
        expect(render().classes()).toContain(chamfer);
    });

    it.each(['HudButton', 'HudInput', 'HudSelect', 'HudTextarea', 'HudToggle'])('%s has a 44 px minimum height', (name) => {
        const control = {
            HudButton: () => mount(HudButton, { slots: { default: 'Ok' } }),
            HudInput: () => mount(HudInput).get('input'),
            HudSelect: () => mount(HudSelect, { props: { options } }).get('select'),
            HudTextarea: () => mount(HudTextarea).get('textarea'),
            HudToggle: () => mount(HudToggle),
        }[name]!();

        expect(control.classes()).toContain('min-h-11');
    });

    it('lights the border from the chamfered corner on hover and focus', () => {
        expect(mount(HudButton, { slots: { default: 'Ok' } }).classes()).toContain('corner-light');
        expect(mount(HudPanel).classes()).toContain('corner-light');
    });

    it('renders the primary button on a solid cyan background', () => {
        expect(mount(HudButton, { slots: { default: 'Ok' } }).classes()).toEqual(
            expect.arrayContaining(['bg-cyan-300', 'text-abyss-950']),
        );
    });

    it('never uses rounded corners outside status dots', async () => {
        const toasts = useToastStore();
        toasts.push('Aviso', { variant: 'info', timeoutMs: 0 });

        const wrappers = [
            mount(HudPanel, { props: { title: 'Painel' }, slots: { default: 'x' } }),
            mount(HudButton, { props: { loading: true }, slots: { default: 'Ok' } }),
            mount(HudInput, { props: { label: 'Campo', error: 'Erro' } }),
            mount(HudSelect, { props: { options, label: 'Lista' } }),
            mount(HudTextarea, { props: { label: 'Texto' } }),
            mount(HudToggle, { props: { label: 'Ligar', modelValue: true } }),
            mount(HudModal, { props: { open: true, title: 'Modal' } }),
            mount(HudToast),
            mount(StatusBadge, { props: { status: 'running' } }),
            mount(ProgressBar, { props: { value: 50, label: 'CPU' } }),
            mount(Waveform, { props: { level: 0.5, bars: 4 } }),
            mount(Clock),
        ];

        await nextTick();

        for (const wrapper of wrappers) {
            const rounded = wrapper.findAll('[class*="rounded"]').filter((element) => element.attributes('data-dot') === undefined);

            expect(rounded.map((element) => element.html())).toEqual([]);
            expect(wrapper.attributes('class') ?? '').not.toMatch(/\brounded/);
            wrapper.unmount();
        }
    });
});

describe('HudToast accents (UI-25)', () => {
    it.each<ToastVariant>(['warning', 'success', 'danger', 'info'])('gives the %s toast its accent class', async (variant) => {
        useToastStore().push('Mensagem', { variant, timeoutMs: 0 });

        const wrapper = mount(HudToast);
        await nextTick();

        const toast = wrapper.get(`[data-variant="${variant}"]`);
        expect(toast.classes()).toEqual(expect.arrayContaining([`toast--accent-${variant}`, 'glass', 'chamfer-md']));
    });

    it('keeps the polite live region', () => {
        expect(mount(HudToast).attributes('aria-live')).toBe('polite');
    });

    it.each([
        ['approval_pending', 'warning'],
        ['task_completed', 'success'],
        ['task_failed', 'danger'],
        ['connector_error', 'danger'],
        ['usage_limit_reached', 'danger'],
        ['memory_hypothesis', 'info'],
        ['constructor', 'info'],
    ])('maps the %s notification to the %s accent', (type, variant) => {
        expect(notificationToastVariant(type)).toBe(variant);
    });
});
