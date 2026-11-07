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
import { useVoiceStore } from '@/stores/voice';

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
const voice = useVoiceStore();
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
    tasks.load().catch(() => undefined);
});

const toolTone = {
    active: 'bg-cyan-300',
    error: 'bg-danger-400',
    idle: 'bg-text-400',
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
        <!-- Section sheets (mobile agents/tasks) skip the core: it already sits on top of the screen. -->
        <HudPanel v-if="expandedSection === null" :title="t('panel.core')">
            <div class="flex items-center justify-center py-2">
                <JarvisCore
                    :state="assistant.state"
                    :level="assistant.audioLevel"
                    :voice-phase="voice.available ? voice.phase : null"
                    :mic-level="voice.micLevel"
                    :output-level="voice.outputLevel"
                    :muted="voice.muted"
                    size="compact"
                />
            </div>
            <div class="mt-2 flex items-center justify-center gap-2" data-testid="panel-core-activity">
                <span
                    class="size-1.5 rounded-full"
                    data-dot
                    :class="assistant.state === 'IDLE' ? 'animate-hud-pulse bg-cyan-300' : 'bg-biolum-200'"
                    :aria-label="t('panel.activity')"
                ></span>
                <StatusBadge :status="assistant.state" />
            </div>
        </HudPanel>

        <HudPanel v-if="showAgents" :title="t('panel.agents')">
            <EmptyState v-if="agents.agents.length === 0" :message="t('empty.agents')" />
            <ul v-else class="stagger-children flex min-w-0 flex-col gap-2">
                <li
                    v-for="(agent, index) in agents.agents"
                    :key="agent.execution_id"
                    class="flex flex-col gap-1 border-b border-ocean-600/40 pb-2 last:border-none"
                    :style="{ '--i': index }"
                    data-testid="panel-agent"
                >
                    <div class="flex items-center gap-2">
                        <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ agent.agent }}</span>
                        <span v-if="agent.depth > 0" class="label-caps shrink-0 text-[0.55rem] text-text-400" data-testid="panel-agent-depth">
                            {{ t('panel.depth', { depth: agent.depth }) }}
                        </span>
                        <StatusBadge :status="agent.status" />
                        <Waveform :level="0.4" :bars="7" />
                    </div>
                    <span
                        v-if="agent.progress_note"
                        class="label-caps min-w-0 truncate text-[0.55rem] text-cyan-300/90"
                        data-testid="panel-agent-note"
                    >
                        {{ agent.progress_note }}
                    </span>
                </li>
            </ul>
        </HudPanel>

        <HudPanel v-if="showTasks" :title="t('panel.tasks')">
            <EmptyState v-if="tasks.tasks.length === 0" :message="t('empty.tasks')" />
            <ul v-else class="stagger-children flex min-w-0 flex-col gap-3">
                <li
                    v-for="(task, index) in tasks.tasks"
                    :key="task.id"
                    class="flex min-w-0 flex-col gap-1.5 border-b border-ocean-600/40 pb-3 last:border-none"
                    :style="{ '--i': index }"
                    data-testid="panel-task"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-col gap-1.5 text-left"
                        :data-testid="`panel-task-${task.id}`"
                        @click="tasks.openDetail(task.id)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ task.title }}</span>
                            <StatusBadge :status="task.status" />
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span v-if="task.agent" class="label-caps min-w-0 truncate text-[0.55rem] text-text-400">
                                {{ task.agent.name }}
                            </span>
                            <span class="label-caps ml-auto shrink-0 truncate text-[0.55rem] text-cyan-300/90">
                                {{ task.current_step?.name ?? '' }}
                            </span>
                        </div>
                        <ProgressBar :value="task.progress" variant="amber" />
                    </button>
                </li>
            </ul>
        </HudPanel>

        <HudPanel v-if="expandedSection === null" :title="t('panel.llmStatus')">
            <EmptyState v-if="models.providers.length === 0" :message="t('empty.llm')" />
            <ul v-else class="flex min-w-0 flex-col gap-1.5" data-testid="panel-llm-list">
                <li
                    v-for="provider in models.providers"
                    :key="provider.name"
                    class="flex items-center gap-2"
                    data-testid="panel-llm-provider"
                >
                    <span class="size-1.5 shrink-0 rounded-full bg-cyan-300" data-dot></span>
                    <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ provider.name }}</span>
                    <span class="label-caps text-[0.55rem] text-text-400">{{ t('panel.llmConnected') }}</span>
                    <span class="font-mono text-[0.6rem] text-biolum-200">{{ provider.count }}</span>
                </li>
            </ul>
        </HudPanel>

        <HudPanel v-if="expandedSection === null" :title="t('panel.memoryInsights')" data-testid="panel-memory-insights">
            <div class="flex min-w-0 flex-col gap-1.5">
                <div class="flex items-center justify-between gap-2" data-testid="panel-memory-confirmed">
                    <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ t('panel.memoriesConfirmed') }}</span>
                    <span class="font-mono text-[0.6rem] text-biolum-200">{{ memories.summary.confirmed }}</span>
                </div>
                <div class="flex items-center justify-between gap-2" data-testid="panel-memory-hypotheses">
                    <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ t('panel.memoriesHypotheses') }}</span>
                    <span class="font-mono text-[0.6rem] text-amber-400">{{ memories.summary.hypotheses }}</span>
                </div>
                <RouterLink
                    :to="{ name: 'memories' }"
                    class="label-caps chamfer-sm corner-light mt-1 flex min-h-11 items-center justify-center border border-ocean-600 px-2 py-1 text-center text-[0.6rem] text-biolum-200 hover:border-cyan-300"
                    data-testid="panel-memory-link"
                >
                    {{ t('panel.openMemories') }}
                </RouterLink>
            </div>
        </HudPanel>

        <HudPanel v-if="expandedSection === null" :title="t('panel.history')">
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
                        class="flex items-center gap-2 border-b border-ocean-600/40 pb-1.5 last:border-none"
                        data-testid="panel-agent-history-entry"
                    >
                        <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ entry.agent }}</span>
                        <span v-if="entry.depth > 0" class="label-caps shrink-0 text-[0.55rem] text-text-400">
                            {{ t('panel.depth', { depth: entry.depth }) }}
                        </span>
                        <span class="label-caps shrink-0 text-[0.55rem] text-text-400">{{ formatDuration(entry.duration_ms) }}</span>
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
                        <span class="shrink-0 text-text-400">{{ formatTimestamp(entry.timestamp) }}</span>
                        <span class="min-w-0 truncate text-cyan-300/90">{{ entry.text }}</span>
                    </li>
                </ol>
            </template>
        </HudPanel>

        <HudPanel v-if="expandedSection === null" :title="t('panel.tools')">
            <EmptyState v-if="tools.tools.length === 0" :message="t('empty.tools')" />
            <ul v-else class="flex min-w-0 flex-col gap-1.5">
                <li
                    v-for="tool in tools.tools"
                    :key="tool.id"
                    class="flex items-center gap-2"
                    data-testid="panel-tool"
                >
                    <span class="size-1.5 shrink-0 rounded-full" :class="toolTone[tool.status]" data-dot></span>
                    <span class="label-caps min-w-0 flex-1 truncate text-[0.65rem] text-text-100">{{ tool.name }}</span>
                    <span class="label-caps text-[0.55rem] text-text-400">{{ t(`toolStatus.${tool.status}`) }}</span>
                </li>
            </ul>
        </HudPanel>
    </div>
</template>
