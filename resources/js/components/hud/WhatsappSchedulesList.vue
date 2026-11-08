<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import { HttpError } from '@/lib/http';
import { useToastStore } from '@/stores/toast';
import { useWhatsappStore } from '@/stores/whatsapp';
import type { WhatsappSchedule, WhatsappScheduleStatus } from '@/types/models';

const CANCELLABLE: readonly WhatsappScheduleStatus[] = ['active', 'paused'];

const statusClasses: Record<WhatsappScheduleStatus, string> = {
    active: 'border-cyan-300/60 bg-cyan-300/10 text-biolum-200',
    paused: 'border-amber-400/60 bg-amber-400/10 text-amber-400',
    cancelled: 'border-text-400/60 bg-text-400/10 text-text-400',
    completed: 'border-ocean-400/60 bg-ocean-400/10 text-ocean-400',
};

/** UI-05: next runs are always shown in the schedule's own timezone. */
const nextRunFormatter = new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' });

const { t } = useI18n();
const whatsapp = useWhatsappStore();
const toasts = useToastStore();

const busyId = ref<number | null>(null);

const showEmpty = computed(() => whatsapp.schedulesLoaded && whatsapp.schedules.length === 0);

function formatNextRun(schedule: WhatsappSchedule): string {
    return schedule.next_run_at === null ? t('whatsapp.schedules.noNextRun') : nextRunFormatter.format(new Date(schedule.next_run_at));
}

async function act(schedule: WhatsappSchedule, action: 'cancel' | 'resume'): Promise<void> {
    busyId.value = schedule.id;

    try {
        if (action === 'cancel') {
            await whatsapp.cancelSchedule(schedule.id);
            toasts.push(t('whatsapp.schedules.cancelled'), { variant: 'success' });
        } else {
            await whatsapp.resumeSchedule(schedule.id);
            toasts.push(t('whatsapp.schedules.resumed'), { variant: 'success' });
        }
    } catch (error) {
        const conflict = error instanceof HttpError && error.status === 409;

        toasts.push(conflict ? t('whatsapp.errors.scheduleConflict') : t('whatsapp.errors.actionFailed'), { variant: 'danger' });
    } finally {
        busyId.value = null;
    }
}

onMounted(() => {
    if (!whatsapp.schedulesLoaded) {
        void whatsapp.loadSchedules().catch(() => toasts.push(t('whatsapp.errors.loadFailed'), { variant: 'danger' }));
    }
});
</script>

<template>
    <HudPanel id="whatsapp-schedules" :title="t('whatsapp.schedules.title')" data-testid="whatsapp-schedules">
        <p v-if="!whatsapp.schedulesLoaded" class="label-caps text-[0.65rem] text-text-400" data-testid="whatsapp-schedules-loading">
            {{ t('common.loading') }}
        </p>

        <EmptyState v-else-if="showEmpty" :message="t('whatsapp.schedules.empty')" data-testid="whatsapp-schedules-empty" />

        <ul v-else class="flex flex-col gap-3" data-testid="whatsapp-schedules-list">
            <li
                v-for="schedule in whatsapp.schedules"
                :key="schedule.id"
                class="chamfer-md flex min-w-0 flex-col gap-2 border border-ocean-600/70 bg-abyss-900/40 p-3"
                :data-testid="`whatsapp-schedule-${schedule.id}`"
            >
                <dl class="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[auto_1fr]">
                    <dt class="label-caps text-[0.6rem] text-text-400">{{ t('whatsapp.schedules.recipient') }}</dt>
                    <dd class="truncate font-mono text-text-100" data-testid="whatsapp-schedule-recipient">{{ schedule.to }}</dd>
                    <dt class="label-caps text-[0.6rem] text-text-400">{{ t('whatsapp.schedules.kind') }}</dt>
                    <dd class="text-text-100" data-testid="whatsapp-schedule-kind">{{ t(`whatsapp.schedules.kinds.${schedule.kind}`) }}</dd>
                    <dt class="label-caps text-[0.6rem] text-text-400">{{ t('whatsapp.schedules.nextRun') }}</dt>
                    <dd class="font-mono text-text-100" data-testid="whatsapp-schedule-next-run">{{ formatNextRun(schedule) }}</dd>
                    <dt class="label-caps text-[0.6rem] text-text-400">{{ t('whatsapp.schedules.recurrence') }}</dt>
                    <dd class="font-mono text-text-100" data-testid="whatsapp-schedule-recurrence">
                        {{ schedule.recurrence ?? t('whatsapp.schedules.once') }}
                    </dd>
                    <dt class="label-caps text-[0.6rem] text-text-400">{{ t('whatsapp.schedules.status') }}</dt>
                    <dd>
                        <span
                            class="label-caps inline-flex border px-2 py-0.5 text-[0.6rem]"
                            :class="statusClasses[schedule.status]"
                            data-testid="whatsapp-schedule-status"
                        >
                            {{ t(`whatsapp.schedules.statuses.${schedule.status}`) }}
                        </span>
                    </dd>
                </dl>

                <div v-if="CANCELLABLE.includes(schedule.status)" class="flex flex-wrap justify-end gap-2">
                    <HudButton
                        v-if="schedule.status === 'paused'"
                        variant="primary"
                        :disabled="busyId === schedule.id"
                        :data-testid="`whatsapp-schedule-resume-${schedule.id}`"
                        @click="act(schedule, 'resume')"
                    >
                        {{ t('whatsapp.schedules.resume') }}
                    </HudButton>
                    <HudButton
                        variant="danger"
                        :disabled="busyId === schedule.id"
                        :data-testid="`whatsapp-schedule-cancel-${schedule.id}`"
                        @click="act(schedule, 'cancel')"
                    >
                        {{ t('whatsapp.schedules.cancel') }}
                    </HudButton>
                </div>
            </li>
        </ul>
    </HudPanel>
</template>
