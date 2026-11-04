<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { TimelineEntry } from '@/stores/taskTimeline';

defineProps<{
    entries: TimelineEntry[];
}>();

const { t } = useI18n();

const iconClasses: Record<TimelineEntry['kind'], string> = {
    tool_started: 'text-cyan-bright',
    tool_completed: 'text-success',
    tool_failed: 'text-danger',
    progress_note: 'text-purple',
};
</script>

<template>
    <ol class="flex flex-col gap-1.5" :aria-label="t('agentsPanel.timeline')" data-testid="task-timeline">
        <li
            v-for="entry in entries"
            :key="entry.key"
            class="flex min-w-0 items-start gap-2 text-[0.7rem] leading-snug"
            :data-kind="entry.kind"
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
                <!-- Tool started: play. -->
                <path v-if="entry.kind === 'tool_started'" d="M7 4l12 8-12 8z" />
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
                    :class="[
                        entry.kind === 'progress_note' ? 'italic text-muted' : 'text-text',
                        entry.kind === 'tool_failed' ? 'text-danger' : '',
                    ]"
                    data-testid="timeline-entry-text"
                    >{{ entry.text }}</span
                >
                <span v-if="entry.detail !== null" class="ml-1 break-all font-mono text-[0.6rem] text-muted" data-testid="timeline-entry-detail">{{
                    entry.detail
                }}</span>
            </span>
        </li>
    </ol>
</template>
