<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export type HudButtonVariant = 'primary' | 'ghost' | 'danger';

const props = withDefaults(
    defineProps<{
        variant?: HudButtonVariant;
        type?: 'button' | 'submit' | 'reset';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', type: 'button', loading: false, disabled: false, block: false },
);

const { t } = useI18n();

const variantClasses: Record<HudButtonVariant, string> = {
    primary: 'border-cyan bg-cyan/15 text-cyan-bright hover:bg-cyan/25 hud-glow',
    ghost: 'border-line bg-transparent text-text hover:border-cyan hover:text-cyan-bright',
    danger: 'border-danger/70 bg-danger/10 text-danger hover:bg-danger/20',
};

const classes = computed(() => [
    'hud-label inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border px-4 py-2 text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-50',
    variantClasses[props.variant],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <button
        :type="type"
        :class="classes"
        :disabled="disabled || loading"
        :aria-busy="loading"
        :data-variant="variant"
    >
        <span
            v-if="loading"
            class="size-3 animate-spin rounded-full border-2 border-current border-t-transparent"
            role="status"
            :aria-label="t('common.loading')"
        ></span>
        <slot />
    </button>
</template>
