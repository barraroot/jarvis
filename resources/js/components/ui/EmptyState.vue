<script setup lang="ts">
import { useI18n } from 'vue-i18n';

withDefaults(
    defineProps<{
        message?: string;
    }>(),
    { message: undefined },
);

const { t } = useI18n();
</script>

<template>
    <div class="flex min-h-20 flex-col items-center justify-center gap-2 px-3 py-6 text-center" data-testid="empty-state">
        <span class="h-px w-10 bg-line"></span>
        <p class="hud-label text-[0.65rem] text-muted">{{ message ?? t('common.noData') }}</p>
        <slot />
    </div>
</template>
