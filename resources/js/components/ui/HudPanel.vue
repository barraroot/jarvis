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
    <component :is="tag" class="hud-border hud-glass relative flex min-w-0 flex-col rounded-sm p-4" data-testid="hud-panel">
        <header v-if="title || $slots.actions" class="mb-3 flex items-center justify-between gap-2 border-b border-line/70 pb-2">
            <h2 v-if="title" class="hud-label truncate text-xs text-cyan hud-glow sm:text-sm">{{ title }}</h2>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div class="min-w-0 flex-1">
            <slot />
        </div>
    </component>
</template>
