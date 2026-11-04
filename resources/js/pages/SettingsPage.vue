<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConnectorsPanel from '@/components/hud/ConnectorsPanel.vue';
import InstallAppPanel from '@/components/hud/InstallAppPanel.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudModal from '@/components/ui/HudModal.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudSelect from '@/components/ui/HudSelect.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import { isSpeechRecognitionSupported } from '@/lib/voice/SpeechRecognizer';
import { DEFAULT_PREFERENCES, usePreferencesStore, type PreferenceUpdate } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';

const { t, locale } = useI18n();
const preferences = usePreferencesStore();
const toasts = useToastStore();

const speechRecognitionSupported = isSpeechRecognitionSupported();

/**
 * Voice and language preferences. The conversation voice runs on OpenAI
 * Realtime, so the browser voice, rate and silence fields are gone; only the
 * languages and the local wake word remain (RF-22).
 */
const form = reactive({
    locale: DEFAULT_PREFERENCES.locale,
    speechLocale: DEFAULT_PREFERENCES.speech_locale,
    wakeWordEnabled: DEFAULT_PREFERENCES.wake_word_enabled,
});

const consentOpen = ref(false);
/** The stored consent covers the current consent text (RF-22). */
const consentValid = ref(false);
/** A consent exists but predates the current text: it must be accepted again (RF-22). */
const consentOutdated = ref(false);
const saving = ref(false);
const loading = ref(true);

const localeOptions = [
    { value: 'pt-BR', label: 'Português (Brasil)' },
    { value: 'en-US', label: 'English (US)' },
];

const consentTitle = computed(() => (consentOutdated.value ? t('settings.wakeWordReconsentTitle') : t('settings.wakeWordConsentTitle')));

/** Switching the wake word on requires accepting the current consent text (RF-22). */
function onWakeWordChange(enabled: boolean): void {
    if (enabled && !consentValid.value) {
        form.wakeWordEnabled = false;
        consentOpen.value = true;

        return;
    }

    form.wakeWordEnabled = enabled;
}

function confirmConsent(): void {
    consentValid.value = true;
    consentOutdated.value = false;
    consentOpen.value = false;
    form.wakeWordEnabled = true;
    void save(true);
}

function cancelConsent(): void {
    consentOpen.value = false;
    form.wakeWordEnabled = false;
}

async function save(consentAccepted = false): Promise<void> {
    if (form.wakeWordEnabled && !consentValid.value) {
        consentOpen.value = true;

        return;
    }

    saving.value = true;

    const patch: PreferenceUpdate = {
        locale: form.locale,
        speech_locale: form.speechLocale,
        wake_word_enabled: form.wakeWordEnabled,
        // The backend demands the consent flag on every payload that enables the wake word.
        wake_word_consent: form.wakeWordEnabled || consentAccepted ? true : undefined,
    };

    try {
        const saved = await preferences.update(patch);

        consentValid.value = saved.wake_word_consent_valid;
        toasts.push(t('settings.saved'), { variant: 'success' });
    } catch {
        // The preferences store already surfaced the failure through a toast.
    } finally {
        saving.value = false;
    }
}

// Changing the interface language switches vue-i18n live (US-4.5).
watch(
    () => form.locale,
    (next) => {
        locale.value = next;
    },
);

onMounted(async () => {
    const preference = await preferences.ensureLoaded();

    form.locale = preference.locale;
    form.speechLocale = preference.speech_locale;
    form.wakeWordEnabled = preference.wake_word_enabled;
    consentValid.value = preference.wake_word_consent_valid === true;
    consentOutdated.value = !consentValid.value && preference.wake_word_consented_at !== null;

    // Apply the persisted interface language on load.
    locale.value = preference.locale;

    loading.value = false;
});
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="settings-page">
        <HudPanel :title="t('settings.title')" data-testid="settings-panel">
            <div class="flex flex-col gap-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <HudSelect
                        v-model="form.locale"
                        :label="t('settings.locale')"
                        :options="localeOptions"
                        :disabled="loading"
                        data-testid="settings-locale"
                    />
                    <HudSelect
                        v-model="form.speechLocale"
                        :label="t('settings.speechLocale')"
                        :options="localeOptions"
                        :disabled="loading"
                        data-testid="settings-speech-locale"
                    />
                </div>

                <p class="hud-label text-[0.6rem] text-muted">{{ t('settings.localeHint') }}</p>

                <div class="flex flex-col gap-1 border-t border-line/70 pt-3">
                    <HudToggle
                        :model-value="form.wakeWordEnabled"
                        :label="t('settings.wakeWord')"
                        :disabled="!speechRecognitionSupported"
                        data-testid="settings-wake-word"
                        @update:model-value="onWakeWordChange"
                    />
                    <p class="hud-label text-[0.6rem] text-muted">{{ t('settings.wakeWordHint') }}</p>
                    <div
                        v-if="consentOutdated"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-warning/60 px-2 py-2"
                        role="alert"
                        data-testid="settings-reconsent"
                    >
                        <p class="hud-label text-[0.6rem] text-warning">{{ t('settings.wakeWordReconsentBody') }}</p>
                        <HudButton variant="ghost" data-testid="settings-reconsent-review" @click="consentOpen = true">
                            {{ t('settings.wakeWordReconsentAction') }}
                        </HudButton>
                    </div>
                    <p
                        v-if="!speechRecognitionSupported"
                        class="hud-label text-[0.6rem] text-warning"
                        role="note"
                        data-testid="settings-wake-word-unsupported"
                    >
                        {{ t('settings.wakeWordUnsupported') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line/70 pt-3">
                    <HudButton variant="primary" :loading="saving" :disabled="loading" data-testid="settings-save" @click="save()">
                        {{ t('settings.save') }}
                    </HudButton>
                </div>
            </div>
        </HudPanel>

        <InstallAppPanel />

        <ConnectorsPanel />

        <HudModal v-model:open="consentOpen" :title="consentTitle" data-testid="settings-consent-modal">
            <p class="text-sm text-text" data-testid="settings-consent-body">{{ t('settings.wakeWordConsentBody') }}</p>
            <template #footer>
                <HudButton variant="ghost" data-testid="settings-consent-cancel" @click="cancelConsent">
                    {{ t('settings.wakeWordConsentCancel') }}
                </HudButton>
                <HudButton variant="primary" data-testid="settings-consent-confirm" @click="confirmConsent">
                    {{ t('settings.wakeWordConsentConfirm') }}
                </HudButton>
            </template>
        </HudModal>
    </div>
</template>
