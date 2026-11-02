<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import HudPanel from '@/components/ui/HudPanel.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import type { ExecutionStatusResultPayload } from '@/types/results';

const props = defineProps<{
    payload: ExecutionStatusResultPayload;
}>();

const { t } = useI18n();

const entityLabel = computed(() => t(props.payload.entity === 'agent' ? 'results.entityAgent' : 'results.entityTool'));
</script>

<template>
    <HudPanel :title="payload.name ?? entityLabel" tag="div" data-testid="result-execution-status">
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <span class="hud-label font-mono text-[0.6rem] text-muted">{{ entityLabel }}</span>
                <StatusBadge :status="payload.status" />
            </div>
            <p class="break-words font-mono text-xs text-text">{{ payload.message }}</p>
        </div>
    </HudPanel>
</template>
