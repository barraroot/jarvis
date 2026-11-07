import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BOOT_ANIMATION, BOOT_ITEM_TIMEOUT_MS, useBootSequence, type BootItem } from '@/composables/useBootSequence';
import { mockFetch } from '../helpers/fetch';

const RESPONSES = {
    memory: { url: '/api/v1/memories?page=1', body: { data: [], meta: {}, summary: { confirmed: 12, hypotheses: 3 } } },
    agents: { url: '/api/v1/agents', body: { data: [{ id: 1 }, { id: 2 }, { id: 3 }] } },
    connectors: {
        url: '/api/v1/connectors',
        body: {
            data: [
                { id: 1, is_active: true, status: 'connected' },
                { id: 2, is_active: true, status: 'error' },
                { id: 3, is_active: false, status: 'error' },
                { id: 4, is_active: true, status: 'connected' },
            ],
        },
    },
    voice: { url: '/api/v1/preferences', body: { data: { wake_word_enabled: true } } },
} as const;

type Deferred = { resolve: (response: Response) => void };

/** Holds every boot request until the test releases it, so each item can be checked in isolation. */
function deferFetch() {
    const pending = new Map<string, Deferred>();

    vi.stubGlobal(
        'fetch',
        vi.fn((input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : input.url;

            return new Promise<Response>((resolve) => {
                pending.set(url, { resolve });
            });
        }),
    );

    function release(key: keyof typeof RESPONSES): void {
        const { url, body } = RESPONSES[key];
        pending.get(url)?.resolve(new Response(JSON.stringify(body), { status: 200 }));
    }

    return { pending, release };
}

function item(items: BootItem[], key: BootItem['key']): BootItem {
    return items.find((candidate) => candidate.key === key)!;
}

afterEach(() => {
    vi.useRealTimers();
});

describe('useBootSequence', () => {
    it('fires the four requests in parallel and completes each item only with its own response', async () => {
        const { pending, release } = deferFetch();
        const boot = useBootSequence();

        const run = boot.run();
        await flushPromises();

        expect([...pending.keys()].sort()).toEqual(Object.values(RESPONSES).map(({ url }) => url).sort());
        expect(boot.items.value.every((candidate) => candidate.status === 'pending')).toBe(true);

        release('agents');
        await flushPromises();
        expect(item(boot.items.value, 'agents')).toEqual({ key: 'agents', status: 'done', value: '3 agentes' });
        expect(item(boot.items.value, 'memory').status).toBe('pending');
        expect(boot.finished.value).toBe(false);

        release('memory');
        release('connectors');
        release('voice');
        await run;

        expect(item(boot.items.value, 'memory').value).toBe('12 confirmadas · 3 hipóteses');
        expect(item(boot.items.value, 'connectors').value).toBe('2 ativos · 1 com erro');
        expect(item(boot.items.value, 'voice').value).toBe('Palavra de ativação ligada');
        expect(boot.finished.value).toBe(true);
    });

    it('marks a failed request as error and still finishes', async () => {
        mockFetch({
            'GET /api/v1/memories?page=1': { body: RESPONSES.memory.body },
            'GET /api/v1/agents': { status: 500, body: { message: 'Server Error' } },
            'GET /api/v1/connectors': { body: RESPONSES.connectors.body },
            'GET /api/v1/preferences': { body: { data: { wake_word_enabled: false } } },
        });
        const boot = useBootSequence();

        await boot.run();

        expect(item(boot.items.value, 'agents')).toEqual({ key: 'agents', status: 'error', value: null });
        expect(item(boot.items.value, 'memory').status).toBe('done');
        expect(item(boot.items.value, 'voice').value).toBe('Palavra de ativação desligada');
        expect(boot.finished.value).toBe(true);
    });

    it('times a pending request out after 5 s and finishes without waiting for it', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        const { release } = deferFetch();
        const boot = useBootSequence();
        const finished = vi.fn();

        void boot.run().then(finished);
        await flushPromises();
        release('memory');
        release('agents');
        release('voice');
        await flushPromises();

        expect(item(boot.items.value, 'connectors').status).toBe('pending');
        expect(finished).not.toHaveBeenCalled();

        vi.advanceTimersByTime(BOOT_ITEM_TIMEOUT_MS);
        await flushPromises();

        expect(BOOT_ITEM_TIMEOUT_MS).toBe(5000);
        expect(item(boot.items.value, 'connectors').status).toBe('error');
        expect(boot.finished.value).toBe(true);
        expect(finished).toHaveBeenCalledOnce();
    });

    it('keeps every boot animation within 2.3 s (UI-14)', () => {
        const steps = [BOOT_ANIMATION.card, ...BOOT_ANIMATION.rings, BOOT_ANIMATION.core];

        expect(BOOT_ANIMATION.rings.map((ring) => ring.delayMs)).toEqual([100, 600, 1100]);
        expect(BOOT_ANIMATION.rings.every((ring) => ring.durationMs === 500)).toBe(true);
        expect(BOOT_ANIMATION.core).toEqual({ delayMs: 1500, durationMs: 800 });
        expect(Math.max(...steps.map((step) => step.delayMs + step.durationMs))).toBeLessThanOrEqual(2300);
    });
});
