<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useReducedMotion } from '@/composables/useReducedMotion';
import type { TimelineEntry } from '@/stores/taskTimeline';

const props = withDefaults(
    defineProps<{
        entries: TimelineEntry[];
        /** Agent running the task: the `A` of a delegation line `A → B` (UI-11). */
        agentName?: string | null;
    }>(),
    { agentName: null },
);

const { t } = useI18n();
const reducedMotion = useReducedMotion();

const DELEGATION_PREFIX = 'delegate.';
/** Name the backend gives delegation tools (`DelegationService::syncTools`). */
const DELEGATION_NAME_PREFIX = 'Delegar para ';

const iconClasses: Record<TimelineEntry['kind'], string> = {
    tool_started: 'text-biolum-200',
    tool_completed: 'text-success-400',
    tool_failed: 'text-danger-400',
    progress_note: 'text-text-400',
};

/** `{from, to}` when a started tool is a `delegate.<slug>` call; null otherwise. */
function delegation(entry: TimelineEntry): { from: string; to: string } | null {
    if (entry.kind !== 'tool_started' || typeof entry.tool !== 'string' || !entry.tool.startsWith(DELEGATION_PREFIX)) {
        return null;
    }

    const to = entry.text.startsWith(DELEGATION_NAME_PREFIX)
        ? entry.text.slice(DELEGATION_NAME_PREFIX.length)
        : entry.tool.slice(DELEGATION_PREFIX.length);

    return { from: props.agentName ?? t('captions.jarvis'), to };
}
</script>

<template>
    <ol class="flex flex-col gap-1.5" :aria-label="t('agentsPanel.timeline')" aria-live="polite" data-testid="task-timeline">
        <li
            v-for="entry in entries"
            :key="entry.key"
            class="timeline-entry flex min-w-0 items-start gap-2 px-1 text-[0.75rem] leading-snug"
            :class="{ 'timeline-entry--enter': !reducedMotion }"
            :data-kind="entry.kind"
            :data-delegation="delegation(entry) !== null ? 'true' : undefined"
            data-testid="timeline-entry"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                class="mt-0.5 size-3 shrink-0"
                :class="iconClasses[entry.kind]"
                aria-hidden="true"
            >
                <!-- Delegation: branching arrow. -->
                <path v-if="delegation(entry) !== null" d="M4 6h8l4 6-4 6H4M16 12h4" />
                <!-- Tool started: play. -->
                <path v-else-if="entry.kind === 'tool_started'" d="M7 4l12 8-12 8z" />
                <!-- Result received: check. -->
                <path v-else-if="entry.kind === 'tool_completed'" d="M5 12l5 5L20 7" />
                <!-- Failure: cross. -->
                <path v-else-if="entry.kind === 'tool_failed'" d="M6 6l12 12M18 6L6 18" />
                <!-- Short thought: ellipsis bubble. -->
                <template v-else>
                    <circle cx="6" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="18" cy="12" r="1" />
                </template>
            </svg>
            <span class="min-w-0 flex-1">
                <span
                    v-if="delegation(entry) !== null"
                    class="font-mono text-biolum-200"
                    :aria-label="t('agents.delegation', delegation(entry) ?? {})"
                    data-testid="timeline-entry-delegation"
                    >{{ delegation(entry)?.from }} → {{ delegation(entry)?.to }}</span
                >
                <span
                    v-else
                    :class="[
                        entry.kind === 'progress_note' ? 'italic text-text-400 opacity-80' : 'text-text-100',
                        entry.kind === 'tool_failed' ? 'text-danger-400' : '',
                    ]"
                    data-testid="timeline-entry-text"
                    >{{ entry.text }}</span
                >
                <span
                    v-if="entry.detail !== null"
                    class="ml-1 break-all font-mono text-[0.6rem] text-text-400"
                    data-testid="timeline-entry-detail"
                    >{{ entry.detail }}</span
                >
            </span>
        </li>
    </ol>
</template>

<style scoped>
/* New lines slide in and glow, the glow fading in --dur-glow (UI-11); one shot, never infinite. */
.timeline-entry--enter {
    animation:
        timeline-entry-slide var(--dur-base) var(--ease-fly) 1 both,
        timeline-entry-glow var(--dur-glow) ease-out 1 both;
}

@keyframes timeline-entry-slide {
    from {
        transform: translateX(-12px);
        opacity: 0;
    }

    to {
        transform: none;
        opacity: 1;
    }
}

@keyframes timeline-entry-glow {
    from {
        background-color: color-mix(in srgb, var(--color-cyan-300) 22%, transparent);
        box-shadow: 0 0 18px color-mix(in srgb, var(--color-cyan-300) 60%, transparent);
    }

    to {
        background-color: transparent;
        box-shadow: none;
    }
}
</style>
