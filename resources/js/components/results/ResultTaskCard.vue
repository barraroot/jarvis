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
                <span class="label-caps font-mono text-[0.6rem] text-text-400">#{{ payload.task_id }}</span>
            </div>
            <ProgressBar :value="payload.progress" :label="t('results.progress')" />
            <button
                type="button"
                class="label-caps chamfer-sm corner-light self-start min-h-11 border border-cyan-300/60 px-3 py-1 text-[0.6rem] text-biolum-200 hover:bg-cyan-300/10"
                data-testid="result-task-card-open"
                @click="openTask"
            >
                {{ t('results.openTask') }}
            </button>
        </div>
    </HudPanel>
</template>
