<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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

const form = reactive({
    locale: DEFAULT_PREFERENCES.locale,
    speechLocale: DEFAULT_PREFERENCES.speech_locale,
    voiceName: '',
    speechRate: DEFAULT_PREFERENCES.speech_rate,
    spokenResponsesEnabled: DEFAULT_PREFERENCES.spoken_responses_enabled,
    silenceTimeoutMs: DEFAULT_PREFERENCES.silence_timeout_ms,
    wakeWordEnabled: DEFAULT_PREFERENCES.wake_word_enabled,
});

const voices = ref<SpeechSynthesisVoice[]>([]);
const consentOpen = ref(false);
const consentConfirmed = ref(false);
const saving = ref(false);
const loading = ref(true);

const localeOptions = [
    { value: 'pt-BR', label: 'Português (Brasil)' },
    { value: 'en-US', label: 'English (US)' },
];

const rateLabel = t('settings.speechRate');
const silenceLabel = t('settings.silenceTimeout');

/** Voices of the chosen speech locale (US-4.5); reloaded on `voiceschanged`. */
function refreshVoices(): void {
    const synth = window.speechSynthesis;

    if (synth === undefined) {
        voices.value = [];

        return;
    }

    const localePrefix = form.speechLocale.toLowerCase();
    voices.value = synth.getVoices().filter((voice) => voice.lang.toLowerCase().startsWith(localePrefix));
}

/** "Testar voz" speaks the i18n phrase with the current form settings (US-4.5). */
function testVoice(): void {
    const synth = window.speechSynthesis;
    const Utterance = (window as unknown as Record<string, unknown>).SpeechSynthesisUtterance;

    if (synth === undefined || typeof Utterance !== 'function') {
        return;
    }

    synth.cancel();

    const utterance = new (Utterance as new (text: string) => SpeechSynthesisUtterance)(t('voice.testPhrase'));
    utterance.lang = form.speechLocale;
    utterance.rate = form.speechRate;

    const voice = voices.value.find((candidate) => candidate.name === form.voiceName);

    if (voice !== undefined) {
        utterance.voice = voice;
    }

    synth.speak(utterance);
}

/** Switching the wake word on requires the privacy consent first (US-4.4). */
function onWakeWordChange(enabled: boolean): void {
    if (enabled && !consentConfirmed.value) {
        form.wakeWordEnabled = false;
        consentOpen.value = true;

        return;
    }

    form.wakeWordEnabled = enabled;
}

function confirmConsent(): void {
    consentConfirmed.value = true;
    consentOpen.value = false;
    form.wakeWordEnabled = true;
    void save();
}

function cancelConsent(): void {
    consentOpen.value = false;
    form.wakeWordEnabled = false;
}

async function save(): Promise<void> {
    if (form.wakeWordEnabled && !consentConfirmed.value) {
        consentOpen.value = true;

        return;
    }

    saving.value = true;

    const patch: PreferenceUpdate = {
        locale: form.locale,
        speech_locale: form.speechLocale,
        voice_name: form.voiceName === '' ? null : form.voiceName,
        speech_rate: form.speechRate,
        spoken_responses_enabled: form.spokenResponsesEnabled,
        silence_timeout_ms: form.silenceTimeoutMs,
        wake_word_enabled: form.wakeWordEnabled,
        // The backend demands the consent flag on every payload that enables the wake word.
        wake_word_consent: form.wakeWordEnabled ? consentConfirmed.value : undefined,
    };

    try {
        await preferences.update(patch);
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

watch(
    () => form.speechLocale,
    () => {
        refreshVoices();
    },
);

onMounted(async () => {
    const preference = await preferences.ensureLoaded();

    form.locale = preference.locale;
    form.speechLocale = preference.speech_locale;
    form.voiceName = preference.voice_name ?? '';
    form.speechRate = preference.speech_rate;
    form.spokenResponsesEnabled = preference.spoken_responses_enabled;
    form.silenceTimeoutMs = preference.silence_timeout_ms;
    form.wakeWordEnabled = preference.wake_word_enabled;
    consentConfirmed.value = preference.wake_word_consented_at !== null;

    // Apply the persisted interface language on load.
    locale.value = preference.locale;

    refreshVoices();
    loading.value = false;

    if ('speechSynthesis' in window) {
        window.speechSynthesis.addEventListener('voiceschanged', refreshVoices);
    }
});

onBeforeUnmount(() => {
    if ('speechSynthesis' in window) {
        window.speechSynthesis.removeEventListener('voiceschanged', refreshVoices);
    }
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

                <div class="flex flex-col gap-2">
                    <HudSelect
                        v-model="form.voiceName"
                        :label="t('settings.voice')"
                        :options="voices.map((voice) => ({ value: voice.name, label: `${voice.name} (${voice.lang})` }))"
                        :disabled="loading"
                        data-testid="settings-voice"
                    />
                    <p v-if="!loading && voices.length === 0" class="hud-label text-[0.6rem] text-muted" data-testid="settings-voice-none">
                        {{ t('settings.voiceNone') }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="settings-rate" class="hud-label text-[0.65rem] text-muted">
                        {{ rateLabel }} — {{ form.speechRate.toFixed(1) }}×
                    </label>
                    <input
                        id="settings-rate"
                        v-model.number="form.speechRate"
                        type="range"
                        min="0.5"
                        max="2"
                        step="0.1"
                        class="w-full accent-cyan"
                        data-testid="settings-rate"
                    />
                </div>

                <div class="flex flex-col gap-1">
                    <label for="settings-silence" class="hud-label text-[0.65rem] text-muted">
                        {{ silenceLabel }} — {{ t('settings.silenceTimeoutValue', { ms: form.silenceTimeoutMs }) }}
                    </label>
                    <input
                        id="settings-silence"
                        v-model.number="form.silenceTimeoutMs"
                        type="range"
                        min="500"
                        max="5000"
                        step="100"
                        class="w-full accent-cyan"
                        data-testid="settings-silence"
                    />
                    <p class="hud-label text-[0.6rem] text-muted">{{ t('settings.silenceTimeoutHint') }}</p>
                </div>

                <div class="flex flex-col gap-1 border-t border-line/70 pt-3">
                    <HudToggle
                        v-model="form.spokenResponsesEnabled"
                        :label="t('settings.spokenResponses')"
                        data-testid="settings-spoken"
                    />
                    <p class="hud-label text-[0.6rem] text-muted">{{ t('settings.spokenResponsesHint') }}</p>
                </div>

                <div class="flex flex-col gap-1 border-t border-line/70 pt-3">
                    <HudToggle
                        :model-value="form.wakeWordEnabled"
                        :label="t('settings.wakeWord')"
                        :disabled="!speechRecognitionSupported"
                        data-testid="settings-wake-word"
                        @update:model-value="onWakeWordChange"
                    />
                    <p class="hud-label text-[0.6rem] text-muted">{{ t('settings.wakeWordHint') }}</p>
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
                    <HudButton variant="ghost" :disabled="loading" data-testid="settings-test-voice" @click="testVoice">
                        {{ t('settings.testVoice') }}
                    </HudButton>
                    <HudButton variant="primary" :loading="saving" :disabled="loading" data-testid="settings-save" @click="save">
                        {{ t('settings.save') }}
                    </HudButton>
                </div>
            </div>
        </HudPanel>

        <HudModal v-model:open="consentOpen" :title="t('settings.wakeWordConsentTitle')" data-testid="settings-consent-modal">
            <p class="text-sm text-text">{{ t('settings.wakeWordConsentBody') }}</p>
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
