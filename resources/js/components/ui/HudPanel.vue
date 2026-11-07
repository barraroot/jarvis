<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        tag?: string;
    }>(),
    { title: undefined, tag: 'section' },
);
</script>

<template>
    <component :is="tag" class="glass chamfer-md corner-light flex min-w-0 flex-col p-4" data-testid="hud-panel">
        <header v-if="title || $slots.actions" class="mb-3 flex items-center justify-between gap-2 border-b border-ocean-600/70 pb-2">
            <h2 v-if="title" class="label-caps truncate text-xs text-cyan-300 glow sm:text-sm">{{ title }}</h2>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div class="min-w-0 flex-1">
            <slot />
        </div>
    </component>
</template>
