<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import ConversationView from '@/components/hud/ConversationView.vue';
import { useLayoutMode } from '@/composables/useLayoutMode';
import { useApprovalsStore } from '@/stores/approvals';
import { useConversationsStore } from '@/stores/conversations';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';

const { t } = useI18n();
const conversations = useConversationsStore();
const visualizations = useVisualizationsStore();
const tasks = useTasksStore();
const approvals = useApprovalsStore();
const { mode } = useLayoutMode();

/** Pending decisions shown on the HUD itself (UI-13); decided cards leave the list. Mobile shows them as a sheet (UI-23). */
const pendingApprovals = computed(() => approvals.items.filter((approval) => approval.status === 'pending'));

/**
 * The HUD landing shows the greeting, never a stale open conversation. A
 * reload brings back the last visualization not closed (CT-08, RF-19b), the
 * active tasks of the AGENTES panel and the pending approvals.
 */
onMounted(() => {
    conversations.close();
    void visualizations.restore();
    tasks.load().catch(() => undefined);
    approvals.load().catch(() => undefined);
});
</script>

<template>
    <!-- Single root: the route transition (UI-24) animates one element. -->
    <div class="min-w-0" data-testid="hud-page">
        <section
            v-if="pendingApprovals.length > 0 && mode !== 'mobile'"
            class="mx-auto mb-4 flex w-full max-w-2xl flex-col gap-3"
            :aria-label="t('approval.title')"
            data-testid="hud-approvals"
        >
            <ApprovalCard v-for="approval in pendingApprovals" :key="approval.id" :approval="approval" />
        </section>
        <ConversationView :show-core="false" />
    </div>
</template>
