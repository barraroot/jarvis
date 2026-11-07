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
    primary: 'border-cyan-300 bg-cyan-300 text-abyss-950 hover:bg-biolum-200',
    ghost: 'border-ocean-600 bg-transparent text-text-100 hover:text-biolum-200',
    danger: 'border-danger-400/70 bg-danger-400/10 text-danger-400 hover:bg-danger-400/20',
};

const classes = computed(() => [
    'label-caps chamfer-sm corner-light inline-flex min-h-11 min-w-11 items-center justify-center gap-2 border px-4 py-2 text-xs disabled:cursor-not-allowed disabled:opacity-50',
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
            class="size-3 animate-spin border-2 border-current border-t-transparent"
            role="status"
            :aria-label="t('common.loading')"
        ></span>
        <slot />
    </button>
</template>
