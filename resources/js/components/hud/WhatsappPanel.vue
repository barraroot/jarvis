<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import HudButton from '@/components/ui/HudButton.vue';
import HudInput from '@/components/ui/HudInput.vue';
import HudModal from '@/components/ui/HudModal.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { HttpError } from '@/lib/http';
import { useToastStore } from '@/stores/toast';
import { useWhatsappStore, WHATSAPP_SCOPES } from '@/stores/whatsapp';
import type { WhatsappScope } from '@/types/models';

type ConfirmAction = 'revoke' | 'remove';

const CONSENT_SECTIONS = ['reading', 'sending', 'aiProvider', 'brakes', 'retention', 'revocation'] as const;

const PHONE_PATTERN = /^\d{10,15}$/;

const { t, locale } = useI18n();
const whatsapp = useWhatsappStore();
const toasts = useToastStore();

/** UI-01: every scope starts unchecked, on every render of the consent form. */
const selectedScopes = ref<Record<WhatsappScope, boolean>>({ leitura: false, envio: false, grupos: false, midia: false });
const phone = ref('');
const phoneError = ref<string | null>(null);
const busy = ref<string | null>(null);
const confirming = ref<ConfirmAction | null>(null);

const confirmOpen = computed({
    get: () => confirming.value !== null,
    set: (open: boolean) => {
        if (!open) {
            confirming.value = null;
        }
    },
});

const session = computed(() => whatsapp.session);
const chosenScopes = computed(() => WHATSAPP_SCOPES.filter((scope) => selectedScopes.value[scope]));
const consentOutdated = computed(() => whatsapp.consent !== null && whatsapp.consent.version !== null && !whatsapp.consent.valid);
const grantedScopes = computed(() => (whatsapp.consent?.scopes ?? []).map((scope) => t(`whatsapp.scopes.${scope}`)).join(', '));
const errorMessage = computed(() => session.value?.last_error ?? t('whatsapp.session.errorFallback'));

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

function failureMessage(error: unknown): string {
    if (error instanceof HttpError && error.serverMessage !== null && error.status !== 500) {
        return error.serverMessage;
    }

    return t('whatsapp.errors.actionFailed');
}

/** Runs one action at a time and reports failures as a toast. */
async function run(action: string, operation: () => Promise<unknown>, success?: string): Promise<boolean> {
    busy.value = action;

    try {
        await operation();

        if (success !== undefined) {
            toasts.push(success, { variant: 'success' });
        }

        return true;
    } catch (error) {
        toasts.push(failureMessage(error), { variant: 'danger' });

        return false;
    } finally {
        busy.value = null;
    }
}

async function acceptConsent(): Promise<void> {
    if (chosenScopes.value.length === 0) {
        return;
    }

    const accepted = await run('consent', () => whatsapp.grantConsent([...chosenScopes.value]), t('whatsapp.consent.accepted'));

    if (accepted) {
        selectedScopes.value = { leitura: false, envio: false, grupos: false, midia: false };
    }
}

async function provision(): Promise<void> {
    await run('provision', () => whatsapp.provision(), t('whatsapp.messages.provisioned'));
}

async function requestQr(): Promise<void> {
    await run('qr', () => whatsapp.requestQr());
}

async function pairPhone(): Promise<void> {
    const digits = phone.value.trim();

    if (!PHONE_PATTERN.test(digits)) {
        phoneError.value = t('whatsapp.pairing.phoneInvalid');

        return;
    }

    phoneError.value = null;
    busy.value = 'phone';

    try {
        await whatsapp.pairPhone(digits);
    } catch (error) {
        if (error instanceof HttpError && error.status === 422) {
            phoneError.value = error.validationErrors.phone?.[0] ?? t('whatsapp.pairing.phoneInvalid');
        } else {
            toasts.push(failureMessage(error), { variant: 'danger' });
        }
    } finally {
        busy.value = null;
    }
}

async function confirmAction(): Promise<void> {
    const action = confirming.value;

    if (action === null) {
        return;
    }

    confirming.value = null;

    if (action === 'revoke') {
        await run('revoke', () => whatsapp.revokeConsent(), t('whatsapp.messages.revoked'));
    } else {
        await run('remove', () => whatsapp.remove(), t('whatsapp.messages.removed'));
    }
}

onMounted(() => {
    if (!whatsapp.loaded) {
        void whatsapp.load().catch(() => toasts.push(t('whatsapp.errors.loadFailed'), { variant: 'danger' }));
    }
});
</script>

<template>
    <HudPanel id="whatsapp" :title="t('whatsapp.title')" data-testid="whatsapp-panel">
        <template v-if="session" #actions>
            <span class="label-caps text-[0.6rem] text-text-400">{{ t('whatsapp.status.label') }}</span>
            <StatusBadge :status="session.status" data-testid="whatsapp-status" />
        </template>

        <p v-if="!whatsapp.loaded" class="label-caps text-[0.65rem] text-text-400" data-testid="whatsapp-loading">
            {{ t('whatsapp.loading') }}
        </p>

        <div v-else class="flex flex-col gap-4">
            <p class="text-sm text-text-400">{{ t('whatsapp.intro') }}</p>

            <section
                v-if="!whatsapp.hasValidConsent"
                class="chamfer-md flex flex-col gap-3 border border-ocean-600/70 bg-abyss-900/40 p-3"
                data-testid="whatsapp-consent"
            >
                <header class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="label-caps text-[0.65rem] text-cyan-300">{{ t('whatsapp.consent.title') }}</h3>
                    <span class="font-mono text-[0.6rem] text-text-400" data-testid="whatsapp-consent-version">
                        {{ t('whatsapp.consent.version', { version: whatsapp.currentConsentVersion }) }}
                    </span>
                </header>
                <p v-if="consentOutdated" class="text-sm text-amber-400" role="alert" data-testid="whatsapp-consent-outdated">
                    {{ t('whatsapp.consent.outdated') }}
                </p>
                <p class="text-sm text-text-100">{{ t('whatsapp.consent.intro') }}</p>
                <ul class="flex list-disc flex-col gap-2 pl-5 text-sm text-text-100" data-testid="whatsapp-consent-text">
                    <li v-for="section in CONSENT_SECTIONS" :key="section">{{ t(`whatsapp.consent.${section}`) }}</li>
                </ul>
                <fieldset class="flex flex-col gap-1">
                    <legend class="mb-1 text-sm text-text-100">{{ t('whatsapp.consent.scopesLabel') }}</legend>
                    <div v-for="scope in WHATSAPP_SCOPES" :key="scope" class="flex flex-col">
                        <HudToggle v-model="selectedScopes[scope]" :label="t(`whatsapp.scopes.${scope}`)" :data-testid="`whatsapp-scope-${scope}`" />
                        <span class="pl-14 text-[0.65rem] text-text-400">{{ t(`whatsapp.scopeHints.${scope}`) }}</span>
                    </div>
                </fieldset>
                <div class="flex justify-end">
                    <HudButton
                        variant="primary"
                        :disabled="chosenScopes.length === 0"
                        :loading="busy === 'consent'"
                        data-testid="whatsapp-consent-accept"
                        @click="acceptConsent"
                    >
                        {{ t('whatsapp.consent.accept') }}
                    </HudButton>
                </div>
            </section>

            <template v-else>
                <div class="flex flex-col gap-1" data-testid="whatsapp-consent-current">
                    <p class="label-caps text-[0.6rem] text-cyan-300">
                        {{ t('whatsapp.consent.current', { version: whatsapp.consent?.version ?? whatsapp.currentConsentVersion }) }}
                    </p>
                    <p class="text-xs text-text-400">{{ t('whatsapp.consent.scopesGranted', { scopes: grantedScopes }) }}</p>
                </div>

                <div v-if="!session" class="flex flex-col items-start gap-2" data-testid="whatsapp-no-session">
                    <p class="text-sm text-text-400">{{ t('whatsapp.session.noSession') }}</p>
                    <HudButton variant="primary" :loading="busy === 'provision'" data-testid="whatsapp-connect" @click="provision">
                        {{ t('whatsapp.actions.connect') }}
                    </HudButton>
                </div>
            </template>

            <template v-if="session">
                <div
                    v-if="session.suspended"
                    class="chamfer-md flex flex-col gap-1 border border-amber-400/60 bg-amber-400/5 p-3"
                    role="status"
                    data-testid="whatsapp-suspended"
                >
                    <p class="label-caps text-[0.65rem] text-amber-400">{{ t('whatsapp.status.suspended') }}</p>
                    <p class="text-sm text-text-100">{{ t('whatsapp.session.suspendedBody') }}</p>
                </div>

                <div
                    v-if="session.status === 'error'"
                    class="chamfer-md flex flex-col items-start gap-2 border border-danger-400/60 bg-danger-400/5 p-3"
                    data-testid="whatsapp-error"
                >
                    <p class="label-caps text-[0.65rem] text-danger-400">{{ t('whatsapp.session.errorTitle') }}</p>
                    <p class="font-mono text-xs break-words text-danger-400" role="alert" data-testid="whatsapp-error-message">
                        {{ errorMessage }}
                    </p>
                    <HudButton
                        variant="ghost"
                        :disabled="session.suspended"
                        :loading="busy === 'provision'"
                        data-testid="whatsapp-retry"
                        @click="provision"
                    >
                        {{ t('whatsapp.actions.retry') }}
                    </HudButton>
                </div>

                <div class="flex flex-col gap-1 font-mono text-[0.65rem] text-text-400" data-testid="whatsapp-session-details">
                    <p v-if="session.phone_masked" data-testid="whatsapp-phone">{{ t('whatsapp.session.phone', { phone: session.phone_masked }) }}</p>
                    <p v-if="session.status === 'connected' && session.connected_at">
                        {{ t('whatsapp.session.connectedAt', { date: formatDate(session.connected_at) }) }}
                    </p>
                    <p v-if="session.last_checked_at">{{ t('whatsapp.session.lastChecked', { date: formatDate(session.last_checked_at) }) }}</p>
                    <p data-testid="whatsapp-usage">
                        {{ t('whatsapp.session.usageToday', { sends: session.usage_today.sends, cap: session.usage_today.cap }) }}
                    </p>
                </div>

                <div v-if="whatsapp.canPair" class="grid gap-3 md:grid-cols-2" data-testid="whatsapp-pairing">
                    <section class="chamfer-md flex flex-col gap-2 border border-ocean-600/70 bg-abyss-900/40 p-3" data-testid="whatsapp-pair-qr">
                        <h3 class="label-caps text-[0.65rem] text-cyan-300">{{ t('whatsapp.actions.connectQr') }}</h3>
                        <template v-if="whatsapp.qr">
                            <p class="text-xs text-text-400">{{ t('whatsapp.pairing.qrHint') }}</p>
                            <img
                                :src="whatsapp.qr"
                                :alt="t('whatsapp.pairing.qrAlt')"
                                class="size-56 self-center bg-white p-2"
                                data-testid="whatsapp-qr-image"
                            />
                            <HudButton variant="ghost" :loading="busy === 'qr'" data-testid="whatsapp-qr-refresh" @click="requestQr">
                                {{ t('whatsapp.actions.newQr') }}
                            </HudButton>
                        </template>
                        <HudButton v-else variant="primary" :loading="busy === 'qr'" data-testid="whatsapp-qr-request" @click="requestQr">
                            {{ t('whatsapp.actions.connectQr') }}
                        </HudButton>
                    </section>

                    <form
                        class="chamfer-md flex flex-col gap-2 border border-ocean-600/70 bg-abyss-900/40 p-3"
                        data-testid="whatsapp-pair-phone"
                        @submit.prevent="pairPhone"
                    >
                        <h3 class="label-caps text-[0.65rem] text-cyan-300">{{ t('whatsapp.actions.connectPhone') }}</h3>
                        <HudInput
                            v-model="phone"
                            type="tel"
                            autocomplete="off"
                            :label="t('whatsapp.pairing.phoneLabel')"
                            :placeholder="t('whatsapp.pairing.phonePlaceholder')"
                            :error="phoneError"
                            data-testid="whatsapp-phone-input"
                        />
                        <HudButton type="submit" variant="primary" :loading="busy === 'phone'" data-testid="whatsapp-phone-submit">
                            {{ t('whatsapp.actions.requestCode') }}
                        </HudButton>
                        <div v-if="whatsapp.pairingCode" class="flex flex-col gap-1" data-testid="whatsapp-pairing-code-box">
                            <p class="label-caps text-[0.6rem] text-cyan-300">{{ t('whatsapp.pairing.codeTitle') }}</p>
                            <p class="font-mono text-2xl tracking-widest text-biolum-200" data-testid="whatsapp-pairing-code">
                                {{ whatsapp.pairingCode }}
                            </p>
                            <p class="text-xs text-text-400">{{ t('whatsapp.pairing.codeHint') }}</p>
                        </div>
                    </form>
                </div>
            </template>

            <div v-if="whatsapp.hasValidConsent || session" class="flex flex-wrap justify-end gap-2 border-t border-ocean-600/70 pt-3">
                <HudButton
                    v-if="whatsapp.hasValidConsent"
                    variant="danger"
                    :loading="busy === 'revoke'"
                    data-testid="whatsapp-revoke"
                    @click="confirming = 'revoke'"
                >
                    {{ t('whatsapp.actions.revoke') }}
                </HudButton>
                <HudButton v-if="session" variant="danger" :loading="busy === 'remove'" data-testid="whatsapp-remove" @click="confirming = 'remove'">
                    {{ t('whatsapp.actions.remove') }}
                </HudButton>
            </div>
        </div>

        <HudModal
            v-model:open="confirmOpen"
            :title="confirming === 'remove' ? t('whatsapp.modals.removeTitle') : t('whatsapp.modals.revokeTitle')"
            data-testid="whatsapp-confirm-modal"
        >
            <p class="text-sm text-text-100" data-testid="whatsapp-confirm-body">
                {{ confirming === 'remove' ? t('whatsapp.modals.removeBody') : t('whatsapp.modals.revokeBody') }}
            </p>
            <template #footer>
                <HudButton variant="ghost" data-testid="whatsapp-confirm-cancel" @click="confirming = null">
                    {{ t('whatsapp.actions.cancel') }}
                </HudButton>
                <HudButton variant="danger" data-testid="whatsapp-confirm-submit" @click="confirmAction">
                    {{ t('whatsapp.actions.confirm') }}
                </HudButton>
            </template>
        </HudModal>
    </HudPanel>
</template>
