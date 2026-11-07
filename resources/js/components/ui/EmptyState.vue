<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * Empty list state (UI-20): `title` + `text` + an `action` slot for the next
 * step, as in `board-10-edge-states.html`; `message` keeps the compact form.
 */
withDefaults(
    defineProps<{
        message?: string;
        title?: string;
        text?: string;
    }>(),
    { message: undefined, title: undefined, text: undefined },
);

const { t } = useI18n();
</script>

<template>
    <div
        class="glass chamfer-md flex min-h-20 flex-col items-center justify-center gap-2 px-4 py-6 text-center"
        data-testid="empty-state"
    >
        <span class="h-px w-10 bg-ocean-600" aria-hidden="true"></span>
        <template v-if="title">
            <h3 class="label-caps text-sm text-biolum-200" data-testid="empty-state-title">{{ title }}</h3>
            <p v-if="text" class="max-w-sm text-sm text-text-400" data-testid="empty-state-text">{{ text }}</p>
        </template>
        <p v-else class="label-caps text-[0.65rem] text-text-400">{{ message ?? t('common.noData') }}</p>
        <div v-if="$slots.action" class="mt-2 flex flex-wrap justify-center gap-2" data-testid="empty-state-action">
            <slot name="action" />
        </div>
        <slot />
    </div>
</template>
