import { ref, type Ref } from 'vue';
import { i18n, translate } from '@/i18n';
import { API_PREFIX, http } from '@/lib/http';
import type { ConnectorSummary, UserPreference } from '@/types/models';

/** Each boot request gives up after 5 s: the item turns into an error and the boot goes on (RF-03). */
export const BOOT_ITEM_TIMEOUT_MS = 5000;

export const BOOT_ITEM_KEYS = ['memory', 'agents', 'connectors', 'voice'] as const;

export type BootItemKey = (typeof BOOT_ITEM_KEYS)[number];

export type BootItemStatus = 'pending' | 'done' | 'error';

export interface BootItem {
    key: BootItemKey;
    status: BootItemStatus;
    /** pt-BR text built from the real response; null while pending or after a failure. */
    value: string | null;
}

export interface BootAnimationStep {
    delayMs: number;
    durationMs: number;
}

/**
 * Boot choreography (UI-14, `board-01-login-boot.html`): the card sinks, the
 * three rings ignite one by one and the nucleus wakes up — 2.3 s at most.
 * Nothing of it runs under reduced motion (RNF-01).
 */
export const BOOT_ANIMATION: { card: BootAnimationStep; rings: readonly BootAnimationStep[]; core: BootAnimationStep } = {
    card: { delayMs: 0, durationMs: 500 },
    rings: [
        { delayMs: 100, durationMs: 500 },
        { delayMs: 600, durationMs: 500 },
        { delayMs: 1100, durationMs: 500 },
    ],
    core: { delayMs: 1500, durationMs: 800 },
};

interface MemoryListEnvelope {
    summary: { confirmed: number; hypotheses: number };
}

interface ListEnvelope<T> {
    data: T[];
}

interface PreferenceEnvelope {
    data: Pick<UserPreference, 'wake_word_enabled'>;
}

/** One real request per checklist item; the resolved text is shown next to the item (RF-04). */
const loaders: Record<BootItemKey, () => Promise<string>> = {
    async memory() {
        const response = await http.get<MemoryListEnvelope>(`${API_PREFIX}/memories?page=1`);

        return translate('boot.memoryValue', {
            confirmed: response.summary.confirmed,
            hypotheses: response.summary.hypotheses,
        });
    },
    async agents() {
        const response = await http.get<ListEnvelope<unknown>>(`${API_PREFIX}/agents`);
        const count = response.data.length;

        return i18n.global.t('boot.agentsValue', { count }, count);
    },
    async connectors() {
        const response = await http.get<ListEnvelope<ConnectorSummary>>(`${API_PREFIX}/connectors`);
        const active = response.data.filter((connector) => connector.is_active);

        return translate('boot.connectorsValue', {
            active: active.filter((connector) => connector.status === 'connected').length,
            errors: active.filter((connector) => connector.status === 'error').length,
        });
    },
    async voice() {
        const response = await http.get<PreferenceEnvelope>(`${API_PREFIX}/preferences`);

        return translate(response.data.wake_word_enabled ? 'boot.voiceOn' : 'boot.voiceOff');
    },
};

function withTimeout<T>(promise: Promise<T>, timeoutMs: number): Promise<T> {
    let timer: ReturnType<typeof setTimeout> | null = null;

    const timeout = new Promise<never>((_resolve, reject) => {
        timer = setTimeout(() => reject(new Error('boot item timed out')), timeoutMs);
    });

    return Promise.race([promise, timeout]).finally(() => {
        if (timer !== null) {
            clearTimeout(timer);
        }
    });
}

function pendingItems(): BootItem[] {
    return BOOT_ITEM_KEYS.map((key) => ({ key, status: 'pending', value: null }));
}

export interface BootSequence {
    items: Ref<BootItem[]>;
    /** True once the four requests settled (answered, failed or timed out). */
    finished: Ref<boolean>;
    /** Fires the four requests in parallel; resolves when all of them settled, never rejects. */
    run: () => Promise<void>;
}

/**
 * Real boot sequence after an accepted login (RF-03): memories, agents, MCP
 * connectors and voice preferences load in parallel, each item completing only
 * with its own response, failing on error or after `BOOT_ITEM_TIMEOUT_MS`.
 */
export function useBootSequence(): BootSequence {
    const items = ref<BootItem[]>(pendingItems());
    const finished = ref(false);

    function settle(key: BootItemKey, status: BootItemStatus, value: string | null): void {
        items.value = items.value.map((item) => (item.key === key ? { key, status, value } : item));
    }

    async function load(key: BootItemKey): Promise<void> {
        try {
            settle(key, 'done', await withTimeout(loaders[key](), BOOT_ITEM_TIMEOUT_MS));
        } catch {
            settle(key, 'error', null);
        }
    }

    async function run(): Promise<void> {
        items.value = pendingItems();
        finished.value = false;

        await Promise.allSettled(BOOT_ITEM_KEYS.map((key) => load(key)));

        finished.value = true;
    }

    return { items, finished, run };
}
