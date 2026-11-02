<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Waveform from '@/components/ui/Waveform.vue';
import JarvisCore from '@/components/hud/JarvisCore.vue';
import { useActivityStore } from '@/stores/activity';
import { useAgentsStore } from '@/stores/agents';
import { useAssistantStore } from '@/stores/assistant';
import { useMemoriesStore } from '@/stores/memories';
import { useModelsStore } from '@/stores/models';
import { useTasksStore } from '@/stores/tasks';
import { useToolsStore } from '@/stores/tools';

const props = withDefaults(
    defineProps<{
        /**
         * Mobile sheets: when a section is expanded, only that section plus the
         * core are rendered (null shows everything).
         */
        expandedSection?: 'agents' | 'tasks' | null;
    }>(),
    { expandedSection: null },
);

const { t, locale } = useI18n();
const assistant = useAssistantStore();
const agents = useAgentsStore();
const tasks = useTasksStore();
const activity = useActivityStore();
const tools = useToolsStore();
const models = useModelsStore();
const memories = useMemoriesStore();

const showAgents = computed(() => props.expandedSection === null || props.expandedSection === 'agents');
const showTasks = computed(() => props.expandedSection === null || props.expandedSection === 'tasks');

/** The LLM status list is real data from the same API the selector consumes. */
onMounted(() => {
    models.load().catch(() => undefined);
    memories.loadSummary();
    agents.loadHistory().catch(() => undefined);
});

const toolTone = {
    active: 'bg-cyan',
    error: 'bg-danger',
    idle: 'bg-muted',
} as const;

function formatTimestamp(timestamp: string): string {
    const date = new Date(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    return new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(date);
}

function formatDuration(durationMs: number): string {
    if (durationMs < 1000) {
        return `${Math.max(0, Math.round(durationMs))} ms`;
    }

    const totalSeconds = Math.round(durationMs / 1000);

    if (totalSeconds < 60) {
        return `${totalSeconds} s`;
    }

    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return seconds > 0 ? `${minutes} min ${seconds} s` : `${minutes} min`;
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4" data-testid="side-panel">
        <HudPanel :title="t('panel.core')">
            <div class="flex items-center justify-center py-2">
                <JarvisCore :state="assistant.state" :level="assistant.audioLevel" size="compact" />
            </div>
            <div class="mt-2 flex items-center justify-center gap-2" data-testid="panel-core-activity">
                <span
                    class="size-1.5 rounded-full"
                    :class="assistant.state === 'IDLE' ? 'animate-hud-pulse bg-cyan' : 'bg-cyan-bright'"
                    :aria-label="t('panel.activity')"
                ></span>
                <StatusBadge :status="assistant.state" />
            </div>
        </HudPanel>

        <HudPanel v-if="showAgents" :title="t('panel.agents')">
            <EmptyState v-if="agents.agents.length === 0" :message="t('empty.agents')" />
            <ul v-else class="flex min-w-0 flex-col gap-2">
                <li
                    v-for="agent in agents.agents"
                    :key="agent.execution_id"
                    class="flex flex-col gap-1 border-b border-line/50 pb-2 last:border-none"
                    data-testid="panel-agent"
                >
                    <div class="flex items-center gap-2">
                        <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ agent.agent }}</span>
                        <span v-if="agent.depth > 0" class="hud-label shrink-0 text-[0.55rem] text-muted" data-testid="panel-agent-depth">
                            {{ t('panel.depth', { depth: agent.depth }) }}
                        </span>
                        <StatusBadge :status="agent.status" />
                        <Waveform :level="0.4" :bars="7" />
                    </div>
                    <span
                        v-if="agent.progress_note"
                        class="hud-label min-w-0 truncate text-[0.55rem] text-cyan/90"
                        data-testid="panel-agent-note"
                    >
                        {{ agent.progress_note }}
                    </span>
                </li>
            </ul>
        </HudPanel>

        <HudPanel v-if="showTasks" :title="t('panel.tasks')">
            <EmptyState v-if="tasks.tasks.length === 0" :message="t('empty.tasks')" />
            <ul v-else class="flex min-w-0 flex-col gap-3">
                <li
                    v-for="task in tasks.tasks"
                    :key="task.id"
                    class="flex min-w-0 flex-col gap-1.5 border-b border-line/50 pb-3 last:border-none"
                    data-testid="panel-task"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ task.title }}</span>
                        <StatusBadge :status="task.status" />
                    </div>
                    <ProgressBar :value="task.progress" variant="amber" />
                </li>
            </ul>
        </HudPanel>

        <HudPanel :title="t('panel.llmStatus')">
            <EmptyState v-if="models.providers.length === 0" :message="t('empty.llm')" />
            <ul v-else class="flex min-w-0 flex-col gap-1.5" data-testid="panel-llm-list">
                <li
                    v-for="provider in models.providers"
                    :key="provider.name"
                    class="flex items-center gap-2"
                    data-testid="panel-llm-provider"
                >
                    <span class="size-1.5 shrink-0 rounded-full bg-cyan"></span>
                    <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ provider.name }}</span>
                    <span class="hud-label text-[0.55rem] text-muted">{{ t('panel.llmConnected') }}</span>
                    <span class="font-mono text-[0.6rem] text-cyan-bright">{{ provider.count }}</span>
                </li>
            </ul>
        </HudPanel>

        <HudPanel :title="t('panel.memoryInsights')" data-testid="panel-memory-insights">
            <div class="flex min-w-0 flex-col gap-1.5">
                <div class="flex items-center justify-between gap-2" data-testid="panel-memory-confirmed">
                    <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ t('panel.memoriesConfirmed') }}</span>
                    <span class="font-mono text-[0.6rem] text-cyan-bright">{{ memories.summary.confirmed }}</span>
                </div>
                <div class="flex items-center justify-between gap-2" data-testid="panel-memory-hypotheses">
                    <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ t('panel.memoriesHypotheses') }}</span>
                    <span class="font-mono text-[0.6rem] text-amber">{{ memories.summary.hypotheses }}</span>
                </div>
                <RouterLink
                    :to="{ name: 'memories' }"
                    class="hud-label mt-1 rounded-sm border border-line px-2 py-1 text-center text-[0.6rem] text-cyan-bright hover:border-cyan"
                    data-testid="panel-memory-link"
                >
                    {{ t('panel.openMemories') }}
                </RouterLink>
            </div>
        </HudPanel>

        <HudPanel :title="t('panel.history')">
            <EmptyState
                v-if="agents.history.length === 0 && activity.entries.length === 0"
                :message="t('empty.history')"
            />
            <template v-else>
                <ul
                    v-if="agents.history.length > 0"
                    class="flex max-h-40 min-w-0 flex-col gap-1.5 overflow-y-auto"
                    data-testid="panel-agent-history"
                >
                    <li
                        v-for="entry in agents.history"
                        :key="entry.execution_id"
                        class="flex items-center gap-2 border-b border-line/50 pb-1.5 last:border-none"
                        data-testid="panel-agent-history-entry"
                    >
                        <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ entry.agent }}</span>
                        <span v-if="entry.depth > 0" class="hud-label shrink-0 text-[0.55rem] text-muted">
                            {{ t('panel.depth', { depth: entry.depth }) }}
                        </span>
                        <span class="hud-label shrink-0 text-[0.55rem] text-muted">{{ formatDuration(entry.duration_ms) }}</span>
                        <StatusBadge :status="entry.status" />
                    </li>
                </ul>
                <ol
                    v-if="activity.entries.length > 0"
                    class="mt-2 flex max-h-44 min-w-0 flex-col gap-1 overflow-y-auto font-mono text-[0.6rem] leading-relaxed"
                    data-testid="panel-activity-list"
                >
                    <li
                        v-for="entry in activity.entries"
                        :key="entry.id"
                        class="flex min-w-0 gap-2"
                        data-testid="panel-activity-entry"
                    >
                        <span class="shrink-0 text-muted">{{ formatTimestamp(entry.timestamp) }}</span>
                        <span class="min-w-0 truncate text-cyan/90">{{ entry.text }}</span>
                    </li>
                </ol>
            </template>
        </HudPanel>

        <HudPanel :title="t('panel.tools')">
            <EmptyState v-if="tools.tools.length === 0" :message="t('empty.tools')" />
            <ul v-else class="flex min-w-0 flex-col gap-1.5">
                <li
                    v-for="tool in tools.tools"
                    :key="tool.id"
                    class="flex items-center gap-2"
                    data-testid="panel-tool"
                >
                    <span class="size-1.5 shrink-0 rounded-full" :class="toolTone[tool.status]"></span>
                    <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ tool.name }}</span>
                    <span class="hud-label text-[0.55rem] text-muted">{{ t(`toolStatus.${tool.status}`) }}</span>
                </li>
            </ul>
        </HudPanel>
    </div>
</template>
