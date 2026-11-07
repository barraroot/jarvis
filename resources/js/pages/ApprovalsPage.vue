<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import { useApprovalsStore } from '@/stores/approvals';
import { useToastStore } from '@/stores/toast';
import type { ApprovalStatus, ApprovalSummary } from '@/types/models';

const { t, locale } = useI18n();
const approvals = useApprovalsStore();
const toasts = useToastStore();

const columns = ['when', 'action', 'agent', 'decision'] as const;

/** Decision colour per status (RF-08): approved cyan, denied danger, expired muted. */
const decisionTones: Partial<Record<ApprovalStatus, string>> = {
    approved: 'text-cyan-300',
    denied: 'text-danger-400',
    expired: 'text-text-400',
};

function decisionLabel(approval: ApprovalSummary): string {
    return approval.status === 'pending' ? '' : t(`approvals.decision.${approval.status}`);
}

/** "Quando": the decision time, or the request time when the server has none. */
function formatWhen(approval: ApprovalSummary): string {
    const date = new Date(approval.decided_at ?? approval.requested_at);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
}

function loadHistory(page: number): void {
    approvals.loadHistory(page).catch(() => {
        toasts.push(t('errors.network'), { variant: 'danger' });
    });
}

onMounted(() => {
    approvals.load().catch(() => {
        toasts.push(t('errors.network'), { variant: 'danger' });
    });
    loadHistory(1);
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-3" data-testid="approvals-page">
        <div class="border-b border-ocean-600/70 pb-2">
            <h1 class="label-caps text-sm text-cyan-300 glow" data-testid="approvals-page-title">
                {{ t('pages.approvals') }}
            </h1>
            <p class="mt-1 max-w-2xl text-xs text-text-400" data-testid="approvals-page-subtitle">
                {{ t('approvals.intro') }}
            </p>
        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto pb-2">
            <section class="flex min-w-0 flex-col gap-3" data-testid="approvals-pending">
                <EmptyState
                    v-if="!approvals.loading && approvals.items.length === 0"
                    :title="t('edge.approvals.title')"
                    :text="t('edge.approvals.text')"
                    data-testid="approvals-empty"
                />

                <ul v-else class="stagger-children flex min-w-0 flex-col gap-3" data-testid="approvals-list">
                    <li v-for="(approval, index) in approvals.items" :key="approval.id" class="min-w-0" :style="{ '--i': index }" data-testid="approvals-item">
                        <ApprovalCard :approval="approval" />
                    </li>
                </ul>
            </section>

            <section v-if="approvals.history.length > 0" class="glass chamfer-md flex min-w-0 flex-col gap-2 p-4" data-testid="approvals-history">
                <h2 class="label-caps border-b border-ocean-600/70 pb-2 text-xs text-cyan-300">{{ t('approvals.history.title') }}</h2>

                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full min-w-[32rem] border-collapse text-xs text-text-100">
                        <thead>
                            <tr class="border-b border-ocean-600 text-left">
                                <th v-for="column in columns" :key="column" scope="col" class="label-caps whitespace-nowrap px-2 py-2 text-[0.65rem] text-text-400">
                                    {{ t(`approvals.history.${column}`) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="approval in approvals.history"
                                :key="approval.id"
                                class="border-b border-ocean-600/40 align-top last:border-0"
                                :data-status="approval.status"
                                data-testid="approvals-history-row"
                            >
                                <td class="whitespace-nowrap px-2 py-2 font-mono tabular-nums text-text-400">{{ formatWhen(approval) }}</td>
                                <td class="px-2 py-2">
                                    <span class="block break-words">{{ approval.tool.name }}</span>
                                    <span
                                        v-if="approval.decision_reason"
                                        class="block break-words text-[0.65rem] text-text-400"
                                        data-testid="approvals-history-reason"
                                    >
                                        {{ t('approvals.history.reason') }}: {{ approval.decision_reason }}
                                    </span>
                                </td>
                                <td class="px-2 py-2 text-text-400">{{ approval.agent ?? '—' }}</td>
                                <td
                                    class="label-caps whitespace-nowrap px-2 py-2 text-[0.65rem]"
                                    :class="decisionTones[approval.status]"
                                    data-testid="approvals-history-decision"
                                >
                                    {{ decisionLabel(approval) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="approvals.historyHasMore" class="flex justify-center pt-1">
                    <HudButton
                        variant="ghost"
                        :loading="approvals.historyLoading"
                        data-testid="approvals-history-more"
                        @click="loadHistory(approvals.historyPage + 1)"
                    >
                        {{ t('approvals.loadMore') }}
                    </HudButton>
                </div>
            </section>
        </div>
    </div>
</template>
