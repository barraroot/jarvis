<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ApprovalCard from '@/components/hud/ApprovalCard.vue';
import { useApprovalsStore } from '@/stores/approvals';
import type { ApprovalSummary, Message } from '@/types/models';

const props = defineProps<{
    message: Message;
}>();

const approvals = useApprovalsStore();
const fetched = ref<ApprovalSummary | null>(null);

const approvalId = String(props.message.payload?.approval_id ?? '');

/** Store entries stay reactive after decisions; the local copy is the fallback. */
const approval = computed(() => approvals.items.find((item) => item.id === approvalId) ?? fetched.value);

onMounted(() => {
    approvals.fetch(approvalId).then(
        (item) => {
            fetched.value = item;
        },
        () => undefined,
    );
});
</script>

<template>
    <ApprovalCard v-if="approval !== null" :approval="approval" />
</template>
