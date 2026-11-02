<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudSelect, { type HudSelectOption } from '@/components/ui/HudSelect.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useActivityHistoryStore } from '@/stores/activityHistory';
import { useTasksStore } from '@/stores/tasks';
import { useToastStore } from '@/stores/toast';
import type { ActivityItem, ActivityType } from '@/types/models';

const { t } = useI18n();
const history = useActivityHistoryStore();
const tasks = useTasksStore();
const toasts = useToastStore();

/** Terminal glyph per entry kind, matching the `jarvis://activity` console. */
const glyphs: Record<ActivityType, string> = {
    agent: '⚡',
    tool: '⚙',
    approval: '✔',
};

const accent: Record<ActivityType, string> = {
    agent: 'text-amber',
    tool: 'text-cyan',
    approval: 'text-purple',
};

const typeOptions: HudSelectOption[] = [
    { value: 'all', label: t('activity.allTypes') },
    { value: 'agent', label: t('activity.types.agent') },
    { value: 'tool', label: t('activity.types.tool') },
    { value: 'approval', label: t('activity.types.approval') },
];

function load(page = 1): void {
    history.load(page).catch(() => {
        toasts.push(t('activity.loadError'), { variant: 'danger' });
    });
}

function formatTime(value: string | null): string {
    if (!value) {
        return '--:--:--';
    }

    const date = new Date(value);

    return `${date.toLocaleDateString()} ${date.toLocaleTimeString([], { hour12: false })}`;
}

function detailLine(item: ActivityItem): string | null {
    if (item.type === 'agent' && typeof item.details.tool_call_count === 'number') {
        return t('activity.toolCalls', { count: item.details.tool_call_count });
    }

    if (item.type === 'tool' && typeof item.details.duration_ms === 'number') {
        return t('activity.duration', { ms: item.details.duration_ms });
    }

    if (item.type === 'approval' && typeof item.details.decision_reason === 'string') {
        return item.details.decision_reason;
    }

    return null;
}

function typeFilterValue(): string {
    return history.filters.type || 'all';
}

function setTypeFilter(value: string): void {
    history.filters.type = value === 'all' ? '' : (value as ActivityType);
}

watch(
    () => [history.filters.from, history.filters.to, history.filters.type],
    () => load(1),
);

onMounted(() => load(1));
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-3" data-testid="activity-page">
        <div class="border-b border-line pb-2">
            <h1 class="hud-label text-sm text-cyan hud-glow" data-testid="activity-page-title">
                {{ t('pages.activity') }}
            </h1>
            <p class="mt-1 font-mono text-[0.65rem] text-muted">
                {{ t('activity.subtitle') }}
            </p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3" data-testid="activity-filters">
            <HudInput v-model="history.filters.from" type="date" :label="t('activity.from')" data-testid="activity-filter-from" />
            <HudInput v-model="history.filters.to" type="date" :label="t('activity.to')" data-testid="activity-filter-to" />
            <HudSelect
                :model-value="typeFilterValue()"
                :options="typeOptions"
                :label="t('activity.type')"
                data-testid="activity-filter-type"
                @update:model-value="setTypeFilter"
            />
        </div>

        <section class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-sm border border-line bg-bg-navy/80" data-testid="activity-terminal">
            <header class="flex items-center gap-2 border-b border-line px-3 py-2">
                <span class="size-2.5 rounded-full bg-danger"></span>
                <span class="size-2.5 rounded-full bg-amber"></span>
                <span class="size-2.5 rounded-full bg-cyan"></span>
                <span class="ml-2 font-mono text-xs text-text">{{ t('activity.terminal') }}</span>
                <span class="ml-auto font-mono text-[0.65rem] text-amber">{{ t('activity.total', { total: history.total }) }}</span>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                <EmptyState v-if="!history.loading && history.items.length === 0" :message="t('activity.empty')" data-testid="activity-empty" />

                <ul v-else class="flex min-w-0 flex-col gap-2" data-testid="activity-list">
                    <li
                        v-for="item in history.items"
                        :key="`${item.type}-${item.id}`"
                        class="min-w-0 rounded-sm border border-line/60 px-3 py-2 font-mono text-xs"
                        :data-type="item.type"
                        data-testid="activity-item"
                    >
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <span :class="accent[item.type]" aria-hidden="true">{{ glyphs[item.type] }}</span>
                            <span class="text-muted">{{ formatTime(item.occurred_at) }}</span>
                            <span class="hud-label text-[0.6rem]" :class="accent[item.type]">{{ t(`activity.types.${item.type}`) }}</span>
                            <span class="min-w-0 truncate text-text">{{ item.title }}</span>
                            <StatusBadge class="ml-auto" :status="item.status" />
                        </div>

                        <div class="mt-1 flex min-w-0 flex-wrap items-center gap-3 pl-5 text-[0.65rem] text-muted">
                            <span v-if="detailLine(item)">{{ detailLine(item) }}</span>
                            <RouterLink
                                v-if="item.conversation_id"
                                :to="{ name: 'conversation', params: { id: String(item.conversation_id) } }"
                                class="text-cyan hover:text-cyan-bright"
                                data-testid="activity-conversation-link"
                            >
                                {{ t('activity.openConversation', { id: item.conversation_id }) }}
                            </RouterLink>
                            <button
                                v-if="item.task_id"
                                type="button"
                                class="text-cyan hover:text-cyan-bright"
                                data-testid="activity-task-link"
                                @click="tasks.openDetail(String(item.task_id))"
                            >
                                {{ t('activity.openTask', { id: item.task_id }) }}
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <footer v-if="history.lastPage > 1" class="flex items-center gap-2 border-t border-line px-3 py-2" data-testid="activity-pagination">
                <HudButton variant="ghost" :disabled="history.page <= 1 || history.loading" data-testid="activity-prev" @click="load(history.page - 1)">
                    {{ t('activity.previous') }}
                </HudButton>
                <span class="font-mono text-[0.65rem] text-muted">{{ t('activity.page', { page: history.page, last: history.lastPage }) }}</span>
                <HudButton
                    variant="ghost"
                    :disabled="history.page >= history.lastPage || history.loading"
                    data-testid="activity-next"
                    @click="load(history.page + 1)"
                >
                    {{ t('activity.next') }}
                </HudButton>
            </footer>
        </section>
    </div>
</template>
