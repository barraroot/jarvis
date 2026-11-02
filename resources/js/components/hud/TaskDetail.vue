<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudModal from '@/components/ui/HudModal.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useTasksStore } from '@/stores/tasks';
import type { TaskLogSummary } from '@/types/models';

const { t } = useI18n();
const tasks = useTasksStore();

const open = computed({
    get: () => tasks.detailOpen,
    set: (value: boolean) => {
        if (!value) {
            tasks.closeDetail();
        }
    },
});

const detail = computed(() => tasks.detail);

const cancellable = computed(() => detail.value !== null && ['queued', 'running', 'waiting_approval'].includes(detail.value.status));
const retryable = computed(() => detail.value !== null && detail.value.status === 'failed');

const logTones: Record<TaskLogSummary['level'], string> = {
    debug: 'text-muted',
    info: 'text-cyan/90',
    warning: 'text-amber',
    error: 'text-danger',
};

const resultText = computed(() => {
    const result = detail.value?.result;

    if (result === null || result === undefined || typeof result !== 'object') {
        return null;
    }

    if (typeof result.text === 'string') {
        return result.text;
    }

    try {
        return JSON.stringify(result, null, 2);
    } catch {
        return null;
    }
});

const time = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

function formatTime(timestamp: string): string {
    const date = new Date(timestamp);

    return Number.isNaN(date.getTime()) ? timestamp : time.format(date);
}

const working = ref(false);

async function cancel(): Promise<void> {
    if (detail.value === null) {
        return;
    }

    working.value = true;

    try {
        await tasks.cancel(detail.value.id);
    } finally {
        working.value = false;
    }
}

async function retry(): Promise<void> {
    if (detail.value === null) {
        return;
    }

    working.value = true;

    try {
        await tasks.retry(detail.value.id);
        tasks.closeDetail();
    } finally {
        working.value = false;
    }
}
</script>

<template>
    <HudModal v-model:open="open" :title="t('tasks.detailTitle')">
        <div v-if="tasks.detailLoading" class="hud-label py-8 text-center text-muted" data-testid="task-detail-loading">
            {{ t('common.loading') }}
        </div>

        <div v-else-if="detail" class="flex min-w-0 flex-col gap-4" data-testid="task-detail">
            <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <h3 class="hud-label truncate text-sm text-text" data-testid="task-detail-title">{{ detail.title }}</h3>
                    <span v-if="detail.agent" class="hud-label truncate text-[0.6rem] text-muted" data-testid="task-detail-agent">
                        {{ t('tasks.agent', { agent: detail.agent.name }) }}
                    </span>
                </div>
                <StatusBadge :status="detail.status" />
            </div>

            <ProgressBar :value="detail.progress" variant="amber" data-testid="task-detail-progress" />

            <p v-if="detail.current_step" class="hud-label text-[0.65rem] text-cyan/90" data-testid="task-detail-step">
                {{ t('tasks.currentStep', { step: detail.current_step.name }) }}
            </p>

            <p v-if="detail.error" class="hud-label rounded-sm border border-danger/40 bg-danger/10 px-2 py-1.5 text-[0.65rem] text-danger" data-testid="task-detail-error">
                {{ t('tasks.error') }}: {{ detail.error }}
            </p>

            <section class="flex flex-col gap-2">
                <h4 class="hud-label text-[0.65rem] uppercase tracking-wider text-muted">{{ t('tasks.steps') }}</h4>
                <EmptyState v-if="!detail.steps || detail.steps.length === 0" :message="t('tasks.noSteps')" />
                <ol v-else class="flex min-w-0 flex-col gap-1.5" data-testid="task-detail-steps">
                    <li
                        v-for="step in detail.steps"
                        :key="step.id"
                        class="flex items-center gap-2 border-b border-line/50 pb-1.5 last:border-none"
                    >
                        <span class="w-6 shrink-0 text-right font-mono text-[0.6rem] text-muted">{{ step.position }}.</span>
                        <span class="hud-label min-w-0 flex-1 truncate text-[0.65rem] text-text">{{ step.name }}</span>
                        <StatusBadge :status="step.status" />
                    </li>
                </ol>
            </section>

            <section v-if="detail.status === 'waiting_approval' && detail.pending_approvals?.length" class="flex flex-col gap-2" data-testid="task-detail-approvals">
                <h4 class="hud-label text-[0.65rem] uppercase tracking-wider text-muted">{{ t('tasks.approvals') }}</h4>
                <ApprovalCard v-for="approval in detail.pending_approvals" :key="approval.id" :approval="approval" />
            </section>

            <section class="flex flex-col gap-2">
                <h4 class="hud-label text-[0.65rem] uppercase tracking-wider text-muted">{{ t('tasks.logs') }}</h4>
                <EmptyState v-if="!detail.logs || detail.logs.data.length === 0" :message="t('tasks.noLogs')" />
                <ol v-else class="flex max-h-44 min-w-0 flex-col gap-1 overflow-y-auto font-mono text-[0.6rem] leading-relaxed" data-testid="task-detail-logs">
                    <li v-for="log in detail.logs.data" :key="log.id" class="flex min-w-0 gap-2">
                        <span class="shrink-0 text-muted">{{ formatTime(log.created_at) }}</span>
                        <span class="shrink-0 uppercase" :class="logTones[log.level]">[{{ log.level }}]</span>
                        <span class="min-w-0 break-words" :class="logTones[log.level]">{{ log.message }}</span>
                    </li>
                </ol>
            </section>

            <section v-if="detail.status === 'completed' || detail.status === 'failed'" class="flex flex-col gap-2">
                <h4 class="hud-label text-[0.65rem] uppercase tracking-wider text-muted">{{ t('tasks.result') }}</h4>
                <EmptyState v-if="resultText === null" :message="t('tasks.noResult')" />
                <pre v-else class="hud-border max-h-48 overflow-y-auto whitespace-pre-wrap rounded-sm bg-bg-navy p-3 font-mono text-[0.65rem] text-text" data-testid="task-detail-result">{{ resultText }}</pre>
            </section>

            <div v-if="cancellable || retryable" class="flex flex-wrap items-center justify-end gap-2 border-t border-line/70 pt-3" data-testid="task-detail-actions">
                <HudButton v-if="cancellable" variant="danger" :loading="working" @click="cancel">
                    {{ t('tasks.cancel') }}
                </HudButton>
                <HudButton v-if="retryable" variant="primary" :loading="working" @click="retry">
                    {{ t('tasks.retry') }}
                </HudButton>
            </div>
        </div>
    </HudModal>
</template>
