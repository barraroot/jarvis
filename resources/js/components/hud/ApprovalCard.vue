<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import HudTextarea from '@/components/ui/HudTextarea.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { useApprovalsStore } from '@/stores/approvals';
import { useToastStore } from '@/stores/toast';
import type { ApprovalSummary } from '@/types/models';

const props = defineProps<{
    approval: ApprovalSummary;
}>();

const { t, locale } = useI18n();
const approvals = useApprovalsStore();
const toasts = useToastStore();
const reducedMotion = useReducedMotion();

const pendingAction = ref<'approve' | 'deny' | null>(null);
const denyReason = ref('');
const askingReason = ref(false);

/** Store entries stay reactive after decisions, so cards re-render the outcome. */
const resolved = computed(() => approvals.items.find((item) => item.id === props.approval.id) ?? props.approval);

const decision = computed(() => (resolved.value.status === 'pending' ? null : resolved.value));

/** Effect chips come only from the `effect` flags (UI-13, RF-04): no risk level is invented. */
const effects = computed(() => {
    const badges: string[] = [];

    if (resolved.value.effect.is_write) {
        badges.push(t('approvals.effects.write'));
    }

    if (resolved.value.effect.is_sensitive) {
        badges.push(t('approvals.effects.sensitive'));
    }

    if (resolved.value.effect.external) {
        badges.push(t('approvals.effects.external'));
    }

    return badges;
});

const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | null = null;

const remainingMs = computed(() => {
    const target = new Date(resolved.value.expires_at).getTime();

    return Math.max(0, target - now.value);
});

const remainingLabel = computed(() => {
    if (resolved.value.status !== 'pending') {
        return null;
    }

    const total = Math.ceil(remainingMs.value / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    return t('approval.expiresIn', { hours, minutes, seconds });
});

const paramsLabel = computed(() => {
    try {
        return JSON.stringify(resolved.value.params, null, 2);
    } catch {
        return String(resolved.value.params);
    }
});

const agentLabel = computed(() => {
    if (resolved.value.agent !== null && resolved.value.agent !== '') {
        return t('approval.requestedBy', { agent: resolved.value.agent });
    }

    return t('approval.requestedByAssistant');
});

onMounted(() => {
    if (resolved.value.status === 'pending') {
        ticker = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    }
});

onBeforeUnmount(() => {
    if (ticker !== null) {
        clearInterval(ticker);
    }
});

async function approve(): Promise<void> {
    pendingAction.value = 'approve';

    try {
        await approvals.approve(resolved.value.id);
        toasts.push(t('approval.approved'), { variant: 'success' });
    } catch {
        toasts.push(t('approval.actionError'), { variant: 'danger' });
    } finally {
        pendingAction.value = null;
        askingReason.value = false;
    }
}

async function deny(): Promise<void> {
    pendingAction.value = 'deny';

    try {
        await approvals.deny(resolved.value.id, denyReason.value.trim() === '' ? undefined : denyReason.value.trim());
        toasts.push(t('approval.denied'), { variant: 'success' });
    } catch {
        toasts.push(t('approval.actionError'), { variant: 'danger' });
    } finally {
        pendingAction.value = null;
        askingReason.value = false;
        denyReason.value = '';
    }
}

function formatDate(timestamp: string): string {
    const date = new Date(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(date);
}
</script>

<template>
    <div
        class="approval-card chamfer-md flex min-w-0 flex-col gap-2.5 border border-amber-400/70 bg-abyss-900/80 p-3 backdrop-blur-[18px]"
        :class="{ 'approval-card--pulse': decision === null && !reducedMotion }"
        data-testid="approval-card"
    >
        <div class="flex items-center justify-between gap-2" data-testid="approval-header">
            <p class="label-caps min-w-0 truncate text-label text-amber-400" data-testid="approval-title">
                {{ t('approval.title') }} — {{ resolved.tool.name }}
            </p>
            <StatusBadge v-if="decision !== null" :status="decision.status" data-testid="approval-status" />
        </div>

        <p class="label-caps text-[0.7rem] text-text-100" data-testid="approval-agent">
            {{ agentLabel }}
        </p>

        <div class="flex min-w-0 flex-col gap-0.5">
            <p class="text-[0.95rem] font-medium text-text-100" data-testid="approval-tool">{{ resolved.tool.name }}</p>
            <p v-if="resolved.tool.description" class="text-body text-text-400" data-testid="approval-description">
                {{ resolved.tool.description }}
            </p>
            <p class="font-mono text-[0.65rem] text-text-400" data-testid="approval-identifier">
                {{ resolved.tool.identifier }}
            </p>
        </div>

        <div v-if="effects.length > 0" class="flex min-w-0 flex-wrap items-center gap-1.5" data-testid="approval-effects">
            <span
                v-for="effect in effects"
                :key="effect"
                class="label-caps chamfer-sm border border-amber-400/60 bg-amber-400/10 px-2 py-0.5 text-[0.65rem] text-amber-400"
                data-testid="approval-effect"
            >
                {{ effect }}
            </span>
        </div>

        <pre
            class="chamfer-sm max-h-32 overflow-y-auto whitespace-pre-wrap break-words border border-ocean-600/60 bg-abyss-950/70 p-2 font-mono text-[0.7rem] leading-relaxed text-cyan-300/90"
            data-testid="approval-params"
        >{{ paramsLabel }}</pre>

        <p v-if="remainingLabel !== null" class="font-mono text-[0.7rem] text-amber-400" data-testid="approval-countdown">
            {{ remainingLabel }}
        </p>

        <p v-else-if="decision !== null && decision.decision_reason !== null" class="font-mono text-[0.7rem] text-text-400" data-testid="approval-reason">
            {{ decision.decision_reason }}
        </p>

        <div v-if="decision === null" class="flex min-w-0 flex-col gap-2" data-testid="approval-actions">
            <div v-if="askingReason" class="flex min-w-0 flex-col gap-1">
                <HudTextarea
                    v-model="denyReason"
                    :label="t('approval.denyReasonLabel')"
                    :placeholder="t('approval.denyReasonPlaceholder')"
                    :maxlength="500"
                    data-testid="approval-deny-reason"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <HudButton
                        variant="danger"
                        :disabled="pendingAction !== null"
                        data-testid="approval-deny-confirm"
                        @click="deny"
                    >
                        {{ t('approval.denyConfirm') }}
                    </HudButton>
                    <HudButton variant="ghost" :disabled="pendingAction !== null" data-testid="approval-deny-cancel" @click="askingReason = false">
                        {{ t('approval.cancel') }}
                    </HudButton>
                </div>
            </div>

            <div v-else class="flex flex-wrap items-center gap-2">
                <HudButton
                    variant="primary"
                    :disabled="pendingAction !== null || remainingMs === 0"
                    data-testid="approval-approve"
                    @click="approve"
                >
                    {{ t('approvals.approve') }}
                </HudButton>
                <HudButton
                    variant="ghost"
                    :disabled="pendingAction !== null || remainingMs === 0"
                    data-testid="approval-deny"
                    @click="askingReason = true"
                >
                    {{ t('approvals.deny') }}
                </HudButton>
                <span class="font-mono text-[0.65rem] text-text-400" data-testid="approval-requested-at">
                    {{ formatDate(resolved.requested_at) }}
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Entry pulse of the amber border (UI-13): a few beats, then steady; none under reduced motion. */
.approval-card--pulse {
    position: relative;
}

.approval-card--pulse::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    border: 1px solid var(--color-amber-400);
    opacity: 0;
    animation: approval-pulse 1.2s ease-in-out 3;
}

@keyframes approval-pulse {
    0%,
    100% {
        opacity: 0;
    }

    50% {
        opacity: 1;
    }
}
</style>
