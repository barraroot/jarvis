import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import WhatsappSchedulesList from '@/components/hud/WhatsappSchedulesList.vue';
import { useToastStore } from '@/stores/toast';
import type { WhatsappSchedule } from '@/types/models';
import { mockFetch } from '../helpers/fetch';

function makeSchedule(overrides: Partial<WhatsappSchedule> = {}): WhatsappSchedule {
    return {
        id: 3,
        to: '5511999998888@s.whatsapp.net',
        kind: 'text',
        recurrence: '0 9 * * 1',
        timezone: 'America/Sao_Paulo',
        next_run_at: '2026-11-09T12:00:00+00:00',
        approval_expires_at: '2027-02-05T12:00:00+00:00',
        status: 'active',
        created_at: '2026-11-07T12:00:00+00:00',
        ...overrides,
    };
}

async function mountList(): Promise<VueWrapper> {
    const wrapper = mount(WhatsappSchedulesList);

    await flushPromises();

    return wrapper;
}

describe('WhatsappSchedulesList', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('lists recipient, kind, next run in America/Sao_Paulo, recurrence and status (UI-05)', async () => {
        mockFetch({
            'GET /api/v1/whatsapp/schedules': {
                body: {
                    data: [
                        makeSchedule(),
                        makeSchedule({ id: 4, to: '120363000000000000@g.us', kind: 'image', recurrence: null, next_run_at: '2026-11-10T02:30:00+00:00' }),
                    ],
                },
            },
        });

        const wrapper = await mountList();
        const first = wrapper.get('[data-testid="whatsapp-schedule-3"]');
        const second = wrapper.get('[data-testid="whatsapp-schedule-4"]');

        expect(wrapper.findAll('[data-testid="whatsapp-schedules-list"] > li')).toHaveLength(2);

        expect(first.get('[data-testid="whatsapp-schedule-recipient"]').text()).toBe('5511999998888@s.whatsapp.net');
        expect(first.get('[data-testid="whatsapp-schedule-kind"]').text()).toBe('Texto');
        expect(first.get('[data-testid="whatsapp-schedule-next-run"]').text()).toMatch(/^09\/11\/2026,? 09:00$/);
        expect(first.get('[data-testid="whatsapp-schedule-recurrence"]').text()).toBe('0 9 * * 1');
        expect(first.get('[data-testid="whatsapp-schedule-status"]').text()).toBe('Ativo');

        expect(second.get('[data-testid="whatsapp-schedule-kind"]').text()).toBe('Imagem');
        expect(second.get('[data-testid="whatsapp-schedule-next-run"]').text()).toMatch(/^09\/11\/2026,? 23:30$/);
        expect(second.get('[data-testid="whatsapp-schedule-recurrence"]').text()).toBe('Envio único');

        expect(first.find('[data-testid="whatsapp-schedule-cancel-3"]').exists()).toBe(true);
        expect(first.find('[data-testid="whatsapp-schedule-resume-3"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('cancels an active schedule through CT-08 and shows it as cancelled', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/schedules': { body: { data: [makeSchedule()] } },
            'POST /api/v1/whatsapp/schedules/3/cancel': { body: { data: makeSchedule({ status: 'cancelled', next_run_at: null }) } },
        });

        const wrapper = await mountList();

        await wrapper.get('[data-testid="whatsapp-schedule-cancel-3"]').trigger('click');
        await flushPromises();

        const item = wrapper.get('[data-testid="whatsapp-schedule-3"]');
        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/whatsapp/schedules/3/cancel')).toBe(true);
        expect(item.get('[data-testid="whatsapp-schedule-status"]').text()).toBe('Cancelado');
        expect(item.get('[data-testid="whatsapp-schedule-next-run"]').text()).toBe('sem próxima execução');
        expect(item.find('[data-testid="whatsapp-schedule-cancel-3"]').exists()).toBe(false);
        expect(useToastStore().toasts.at(-1)?.message).toBe('Agendamento cancelado.');

        wrapper.unmount();
    });

    it('offers "Retomar" and "Cancelar" for a paused schedule and resumes it to active', async () => {
        const { calls } = mockFetch({
            'GET /api/v1/whatsapp/schedules': { body: { data: [makeSchedule({ id: 5, status: 'paused' })] } },
            'POST /api/v1/whatsapp/schedules/5/resume': { body: { data: makeSchedule({ id: 5, status: 'active' }) } },
        });

        const wrapper = await mountList();
        const item = wrapper.get('[data-testid="whatsapp-schedule-5"]');

        expect(item.get('[data-testid="whatsapp-schedule-status"]').text()).toBe('Pausado');
        expect(item.get('[data-testid="whatsapp-schedule-cancel-5"]').text()).toBe('Cancelar');
        const resume = item.get('[data-testid="whatsapp-schedule-resume-5"]');
        expect(resume.text()).toBe('Retomar');

        await resume.trigger('click');
        await flushPromises();

        expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/whatsapp/schedules/5/resume')).toBe(true);
        expect(item.get('[data-testid="whatsapp-schedule-status"]').text()).toBe('Ativo');
        expect(item.find('[data-testid="whatsapp-schedule-resume-5"]').exists()).toBe(false);
        expect(item.find('[data-testid="whatsapp-schedule-cancel-5"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('has no actions for completed or cancelled schedules', async () => {
        mockFetch({
            'GET /api/v1/whatsapp/schedules': {
                body: { data: [makeSchedule({ id: 6, status: 'completed' }), makeSchedule({ id: 7, status: 'cancelled' })] },
            },
        });

        const wrapper = await mountList();

        expect(wrapper.get('[data-testid="whatsapp-schedule-6"]').find('button').exists()).toBe(false);
        expect(wrapper.get('[data-testid="whatsapp-schedule-7"]').find('button').exists()).toBe(false);
        expect(wrapper.get('[data-testid="whatsapp-schedule-6"] [data-testid="whatsapp-schedule-status"]').text()).toBe('Concluído');

        wrapper.unmount();
    });

    it('reports a conflict when the schedule changed meanwhile', async () => {
        mockFetch({
            'GET /api/v1/whatsapp/schedules': { body: { data: [makeSchedule()] } },
            'POST /api/v1/whatsapp/schedules/3/cancel': { status: 409, body: { message: 'Conflito.' } },
        });

        const wrapper = await mountList();

        await wrapper.get('[data-testid="whatsapp-schedule-cancel-3"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="whatsapp-schedule-status"]').text()).toBe('Ativo');
        expect(useToastStore().toasts.at(-1)?.message).toBe('O estado do agendamento mudou. Atualize a lista e tente novamente.');

        wrapper.unmount();
    });

    it('shows the empty state without schedules', async () => {
        mockFetch({ 'GET /api/v1/whatsapp/schedules': { body: { data: [] } } });

        const wrapper = await mountList();

        expect(wrapper.get('[data-testid="whatsapp-schedules-empty"]').text()).toContain('Nenhum agendamento do WhatsApp.');
        expect(wrapper.find('[data-testid="whatsapp-schedules-list"]').exists()).toBe(false);

        wrapper.unmount();
    });
});
