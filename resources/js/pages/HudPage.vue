<script setup lang="ts">
import { onMounted } from 'vue';
import ConversationView from '@/components/hud/ConversationView.vue';
import { useConversationsStore } from '@/stores/conversations';
import { useTasksStore } from '@/stores/tasks';
import { useVisualizationsStore } from '@/stores/visualizations';

const conversations = useConversationsStore();
const visualizations = useVisualizationsStore();
const tasks = useTasksStore();

/**
 * The HUD landing shows the greeting, never a stale open conversation. A
 * reload brings back the last visualization not closed (CT-08, RF-19b) and
 * the active tasks of the AGENTES panel.
 */
onMounted(() => {
    conversations.close();
    void visualizations.restore();
    tasks.load().catch(() => undefined);
});
</script>

<template>
    <ConversationView :show-core="false" />
</template>
