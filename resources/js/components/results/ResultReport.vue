<script setup lang="ts">
import HudPanel from '@/components/ui/HudPanel.vue';
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import type { ReportResultPayload } from '@/types/results';

defineProps<{
    payload: ReportResultPayload;
}>();
</script>

<template>
    <HudPanel :title="payload.title" tag="div" data-testid="result-report">
        <!-- Collapsible sections: the first one starts open. -->
        <details
            v-for="(section, index) in payload.sections"
            :key="index"
            class="group border-b border-ocean-600/40 pb-2 last:border-0 last:pb-0"
            :open="index === 0"
        >
            <summary
                class="label-caps flex min-h-11 cursor-pointer list-none items-center gap-1.5 py-1.5 text-[0.65rem] text-biolum-200 hover:text-cyan-300"
                :data-testid="`result-report-section-${index}`"
            >
                <span aria-hidden="true" class="text-text-400 transition-transform group-open:rotate-90">›</span>
                {{ section.heading }}
            </summary>
            <div class="pl-4">
                <SafeMarkdown :source="section.body" />
            </div>
        </details>
    </HudPanel>
</template>
