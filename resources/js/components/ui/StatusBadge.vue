<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ApprovalStatus, AssistantState, ConnectorStatus, MemoryStatus, TaskStatus } from '@/types/models';

const props = defineProps<{
    status: AssistantState | TaskStatus | string;
}>();

const { t } = useI18n();

const tones = {
    cyan: 'border-cyan-300/60 bg-cyan-300/10 text-biolum-200',
    blue: 'border-ocean-400/60 bg-ocean-400/10 text-ocean-400',
    purple: 'border-biolum-200/60 bg-biolum-200/10 text-biolum-200',
    amber: 'border-amber-400/60 bg-amber-400/10 text-amber-400',
    danger: 'border-danger-400/60 bg-danger-400/10 text-danger-400',
    muted: 'border-text-400/60 bg-text-400/10 text-text-400',
} as const;

type Tone = keyof typeof tones;

const statusTones: Record<AssistantState | TaskStatus | MemoryStatus | ApprovalStatus | ConnectorStatus | 'timed_out', Tone> = {
    IDLE: 'cyan',
    LISTENING: 'blue',
    THINKING: 'purple',
    SPEAKING: 'cyan',
    EXECUTING: 'amber',
    SLEEPING: 'muted',
    ERROR: 'danger',
    DISCONNECTED: 'danger',
    pending: 'muted',
    queued: 'blue',
    running: 'cyan',
    waiting_approval: 'amber',
    completed: 'cyan',
    failed: 'danger',
    timed_out: 'danger',
    cancelled: 'muted',
    confirmed: 'cyan',
    hypothesis: 'amber',
    approved: 'cyan',
    denied: 'danger',
    expired: 'muted',
    connected: 'cyan',
    error: 'danger',
    disconnected: 'muted',
};

/** Accepts `IDLE`, `waiting_approval`, `Waiting approval`, ... */
const key = computed(() => {
    const raw = props.status.trim();

    if (raw.toUpperCase() in statusTones && raw === raw.toUpperCase()) {
        return raw as AssistantState;
    }

    const normalized = raw.toLowerCase().replace(/[\s-]+/g, '_');

    return normalized in statusTones ? (normalized as TaskStatus) : null;
});

const tone = computed<Tone>(() => (key.value ? statusTones[key.value] : 'muted'));
const label = computed(() => t(`status.${key.value ?? 'unknown'}`));
</script>

<template>
    <span
        class="label-caps chamfer-sm inline-flex max-w-full items-center gap-1.5 truncate border px-2 py-0.5 text-[0.6rem]"
        :class="tones[tone]"
        :data-status="key ?? 'unknown'"
        :data-tone="tone"
    >
        <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" data-dot></span>
        {{ label }}
    </span>
</template>
