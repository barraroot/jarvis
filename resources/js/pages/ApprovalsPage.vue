<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import { useApprovalsStore } from '@/stores/approvals';
import { useToastStore } from '@/stores/toast';

const { t } = useI18n();
const approvals = useApprovalsStore();
const toasts = useToastStore();

onMounted(() => {
    approvals.load().catch(() => {
        toasts.push(t('errors.network'), { variant: 'danger' });
    });
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col gap-3" data-testid="approvals-page">
        <div class="border-b border-line pb-2">
            <h1 class="hud-label text-sm text-cyan hud-glow" data-testid="approvals-page-title">
                {{ t('pages.approvals') }}
            </h1>
            <p class="mt-1 font-mono text-[0.65rem] text-muted" data-testid="approvals-page-subtitle">
                {{ t('approval.subtitle') }}
            </p>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto pb-2">
            <EmptyState v-if="!approvals.loading && approvals.items.length === 0" :message="t('empty.approvals')" data-testid="approvals-empty" />

            <ul v-else class="flex min-w-0 flex-col gap-3" data-testid="approvals-list">
                <li v-for="approval in approvals.items" :key="approval.id" class="min-w-0" data-testid="approvals-item">
                    <ApprovalCard :approval="approval" />
                </li>
            </ul>
        </div>
    </div>
</template>
