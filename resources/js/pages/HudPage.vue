<script setup lang="ts">
import { onMounted } from 'vue';
import ConversationView from '@/components/hud/ConversationView.vue';
import { useConversationsStore } from '@/stores/conversations';

const conversations = useConversationsStore();

/** The HUD landing shows the greeting, never a stale open conversation. */
onMounted(() => {
    conversations.close();
});
</script>

<template>
    <ConversationView />
</template>
