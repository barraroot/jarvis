<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AssistantState, TaskStatus } from '@/types/models';

const props = defineProps<{
    status: AssistantState | TaskStatus | string;
}>();

const { t } = useI18n();

const tones = {
    cyan: 'border-cyan/60 bg-cyan/10 text-cyan-bright',
    blue: 'border-blue/60 bg-blue/10 text-blue',
    purple: 'border-purple/60 bg-purple/10 text-purple',
    amber: 'border-amber/60 bg-amber/10 text-amber',
    danger: 'border-danger/60 bg-danger/10 text-danger',
    muted: 'border-muted/60 bg-muted/10 text-muted',
} as const;

type Tone = keyof typeof tones;

const statusTones: Record<AssistantState | TaskStatus, Tone> = {
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
    cancelled: 'muted',
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
        class="hud-label inline-flex max-w-full items-center gap-1.5 truncate rounded-sm border px-2 py-0.5 text-[0.6rem]"
        :class="tones[tone]"
        :data-status="key ?? 'unknown'"
        :data-tone="tone"
    >
        <span class="size-1.5 shrink-0 rounded-full bg-current"></span>
        {{ label }}
    </span>
</template>
