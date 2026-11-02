<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import HudPanel from '@/components/ui/HudPanel.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useTasksStore } from '@/stores/tasks';
import type { TaskCardResultPayload } from '@/types/results';

const props = defineProps<{
    payload: TaskCardResultPayload;
}>();

const { t } = useI18n();
const tasks = useTasksStore();

function openTask(): void {
    void tasks.openDetail(String(props.payload.task_id));
}
</script>

<template>
    <HudPanel :title="payload.title" tag="div" data-testid="result-task-card">
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <StatusBadge :status="payload.status" />
                <span class="hud-label font-mono text-[0.6rem] text-muted">#{{ payload.task_id }}</span>
            </div>
            <ProgressBar :value="payload.progress" :label="t('results.progress')" />
            <button
                type="button"
                class="hud-label self-start rounded-sm border border-cyan/60 px-2 py-1 text-[0.6rem] text-cyan-bright hover:bg-cyan/10"
                data-testid="result-task-card-open"
                @click="openTask"
            >
                {{ t('results.openTask') }}
            </button>
        </div>
    </HudPanel>
</template>
