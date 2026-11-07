<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useActivityHistoryStore } from '@/stores/activityHistory';
import { useTasksStore } from '@/stores/tasks';
import { useToastStore } from '@/stores/toast';
import type { ActivityItem, ActivityType } from '@/types/models';

type ActivityPeriod = 'today' | 'week' | 'month';

const { t, locale } = useI18n();
const history = useActivityHistoryStore();
const tasks = useTasksStore();
const toasts = useToastStore();

const periods: ActivityPeriod[] = ['today', 'week', 'month'];
const typeChips: ActivityType[] = ['agent', 'tool', 'approval'];
const columns = ['time', 'type', 'event', 'duration', 'status'] as const;

/** Type accent in the table, matching the chip colours of `board-07-activity.html`. */
const accent: Record<ActivityType, string> = {
    agent: 'text-amber-400',
    tool: 'text-cyan-300',
    approval: 'text-biolum-200',
};

/** The chosen period chip; null = no period filter (the API default). */
const period = ref<ActivityPeriod | null>(null);

const secondsFormatter = computed(
    () => new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'second', unitDisplay: 'short', maximumFractionDigits: 1 }),
);

function load(page = 1): void {
    history.load(page).catch(() => {
        toasts.push(t('activity.loadError'), { variant: 'danger' });
    });
}

/** Local calendar day as `YYYY-MM-DD`, the format of the `from`/`to` filters. */
function isoDay(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * Period → `from`/`to` (UI-17): today; the last 7 days including today;
 * or the current calendar month, from day 1 until today.
 */
function periodRange(selected: ActivityPeriod): { from: string; to: string } {
    const today = new Date();
    const start = new Date(today);

    if (selected === 'week') {
        start.setDate(today.getDate() - 6);
    } else if (selected === 'month') {
        start.setDate(1);
    }

    return { from: isoDay(start), to: isoDay(today) };
}

function selectPeriod(selected: ActivityPeriod): void {
    if (period.value === selected) {
        period.value = null;
        history.filters.from = '';
        history.filters.to = '';

        return;
    }

    const range = periodRange(selected);

    period.value = selected;
    history.filters.from = range.from;
    history.filters.to = range.to;
}

function selectType(type: ActivityType): void {
    history.filters.type = history.filters.type === type ? '' : type;
}

function formatTime(value: string | null): string {
    if (!value) {
        return '--:--:--';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '--:--:--';
    }

    return [date.getHours(), date.getMinutes(), date.getSeconds()].map((part) => String(part).padStart(2, '0')).join(':');
}

function identifier(item: ActivityItem): string | null {
    return typeof item.details.identifier === 'string' && item.details.identifier !== '' ? item.details.identifier : null;
}

/** Duration only for tool runs (`details.duration_ms` in seconds); `—` elsewhere. */
function duration(item: ActivityItem): string {
    if (item.type === 'tool' && typeof item.details.duration_ms === 'number') {
        return secondsFormatter.value.format(item.details.duration_ms / 1000);
    }

    return '—';
}

function decisionReason(item: ActivityItem): string | null {
    return item.type === 'approval' && typeof item.details.decision_reason === 'string' ? item.details.decision_reason : null;
}

watch(
    () => [history.filters.from, history.filters.to, history.filters.type],
    () => load(1),
);

onMounted(() => load(1));
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-3" data-testid="activity-page">
        <div class="border-b border-ocean-600/70 pb-2">
            <h1 class="label-caps text-sm text-cyan-300 glow" data-testid="activity-page-title">
                {{ t('pages.activity') }}
            </h1>
            <p class="mt-1 text-xs text-text-400">
                {{ t('activity.subtitle') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2" data-testid="activity-filters">
            <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('activity.period.label')" data-testid="activity-periods">
                <button
                    v-for="option in periods"
                    :key="option"
                    type="button"
                    class="label-caps chamfer-sm min-h-11 border px-3 text-[0.65rem] transition-colors"
                    :class="period === option ? 'border-cyan-300 bg-cyan-300/10 text-cyan-300' : 'border-ocean-600 text-text-400 hover:border-ocean-400'"
                    :aria-pressed="period === option"
                    :data-testid="`activity-period-${option}`"
                    @click="selectPeriod(option)"
                >
                    {{ t(`activity.period.${option}`) }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('activity.type')" data-testid="activity-types">
                <button
                    v-for="type in typeChips"
                    :key="type"
                    type="button"
                    class="label-caps chamfer-sm min-h-11 border px-3 text-[0.65rem] transition-colors"
                    :class="history.filters.type === type ? 'border-cyan-300 bg-cyan-300/10 text-cyan-300' : 'border-ocean-600 text-text-400 hover:border-ocean-400'"
                    :aria-pressed="history.filters.type === type"
                    :data-testid="`activity-type-${type}`"
                    @click="selectType(type)"
                >
                    {{ t(`activity.chips.${type}`) }}
                </button>
            </div>

            <span class="ml-auto font-mono text-[0.65rem] text-text-400" data-testid="activity-total">
                {{ t('activity.total', { total: history.total }) }}
            </span>
        </div>

        <section class="glass chamfer-md flex min-h-0 flex-1 flex-col overflow-hidden" data-testid="activity-table-panel">
            <div class="min-h-0 flex-1 overflow-auto p-3">
                <EmptyState v-if="!history.loading && history.items.length === 0" :message="t('activity.empty')" data-testid="activity-empty" />

                <table v-else-if="history.items.length > 0" class="w-full min-w-[36rem] border-collapse text-xs text-text-100" data-testid="activity-table">
                    <thead>
                        <tr class="border-b border-ocean-600 text-left">
                            <th v-for="column in columns" :key="column" scope="col" class="label-caps whitespace-nowrap px-2 py-2 text-[0.65rem] text-text-400">
                                {{ t(`activity.columns.${column}`) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="stagger-children" data-testid="activity-list">
                        <tr
                            v-for="(item, index) in history.items"
                            :key="`${item.type}-${item.id}`"
                            class="border-b border-ocean-600/40 align-top last:border-0"
                            :style="{ '--i': index }"
                            :data-type="item.type"
                            data-testid="activity-item"
                        >
                            <td class="whitespace-nowrap px-2 py-2 font-mono tabular-nums text-text-400" data-testid="activity-time">
                                {{ formatTime(item.occurred_at) }}
                            </td>
                            <td class="label-caps whitespace-nowrap px-2 py-2 text-[0.65rem]" :class="accent[item.type]" data-testid="activity-type">
                                {{ t(`activity.types.${item.type}`) }}
                            </td>
                            <td class="min-w-0 px-2 py-2" data-testid="activity-event">
                                <span class="block break-words">{{ item.title }}</span>
                                <span v-if="identifier(item)" class="block break-all font-mono text-[0.65rem] text-text-400" data-testid="activity-identifier">
                                    {{ identifier(item) }}
                                </span>
                                <span v-if="decisionReason(item)" class="block break-words text-[0.65rem] text-text-400">
                                    {{ decisionReason(item) }}
                                </span>
                                <span v-if="item.conversation_id || item.task_id" class="mt-1 flex flex-wrap gap-3 text-[0.65rem]">
                                    <RouterLink
                                        v-if="item.conversation_id"
                                        :to="{ name: 'conversation', params: { id: String(item.conversation_id) } }"
                                        class="text-cyan-300 hover:text-biolum-200"
                                        data-testid="activity-conversation-link"
                                    >
                                        {{ t('activity.openConversation', { id: item.conversation_id }) }}
                                    </RouterLink>
                                    <button
                                        v-if="item.task_id"
                                        type="button"
                                        class="text-cyan-300 hover:text-biolum-200"
                                        data-testid="activity-task-link"
                                        @click="tasks.openDetail(String(item.task_id))"
                                    >
                                        {{ t('activity.openTask', { id: item.task_id }) }}
                                    </button>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-2 py-2 font-mono tabular-nums text-text-400" data-testid="activity-duration">
                                {{ duration(item) }}
                            </td>
                            <td class="px-2 py-2" data-testid="activity-status">
                                <StatusBadge :status="item.status" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer v-if="history.lastPage > 1" class="flex items-center gap-2 border-t border-ocean-600/70 px-3 py-2" data-testid="activity-pagination">
                <HudButton variant="ghost" :disabled="history.page <= 1 || history.loading" data-testid="activity-prev" @click="load(history.page - 1)">
                    {{ t('activity.previous') }}
                </HudButton>
                <span class="font-mono text-[0.65rem] text-text-400">{{ t('activity.page', { page: history.page, last: history.lastPage }) }}</span>
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
