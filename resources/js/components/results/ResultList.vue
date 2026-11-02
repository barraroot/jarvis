<script setup lang="ts">
import HudPanel from '@/components/ui/HudPanel.vue';
import type { ListResultPayload } from '@/types/results';

defineProps<{
    payload: ListResultPayload;
}>();
</script>

<template>
    <HudPanel :title="payload.title" tag="div" data-testid="result-list">
        <ul class="flex flex-col gap-1.5 font-mono text-xs text-text">
            <li v-for="(item, index) in payload.items" :key="index" class="flex min-w-0 items-baseline gap-2">
                <span class="size-1 shrink-0 translate-y-[-1px] rounded-full bg-cyan/70" aria-hidden="true" />
                <span class="min-w-0">
                    <span class="break-words">{{ item.label }}</span>
                    <span v-if="item.detail" class="block break-words text-muted">{{ item.detail }}</span>
                </span>
            </li>
        </ul>
    </HudPanel>
</template>
