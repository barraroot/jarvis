<script setup lang="ts">
import { RouterView, useRoute } from 'vue-router';
import HudToast from '@/components/ui/HudToast.vue';
import { useRealtimeConnection } from '@/composables/useRealtimeConnection';
import HudLayout from '@/layouts/HudLayout.vue';

const route = useRoute();

useRealtimeConnection();
</script>

<template>
    <HudLayout v-if="route.meta.requiresAuth">
        <template #main>
            <RouterView />
        </template>
    </HudLayout>
    <RouterView v-else />
    <HudToast />
</template>
