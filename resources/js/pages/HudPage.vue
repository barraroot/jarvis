<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudPanel from '@/components/ui/HudPanel.vue';

const { t } = useI18n();
</script>

<template>
    <HudPanel :title="t('pages.hud')">
        <EmptyState />
    </HudPanel>
</template>
