<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConnectorsPanel from '@/components/hud/ConnectorsPanel.vue';
import InstallAppPanel from '@/components/hud/InstallAppPanel.vue';
import HudButton from '@/components/ui/HudButton.vue';
import HudModal from '@/components/ui/HudModal.vue';
import HudPanel from '@/components/ui/HudPanel.vue';
import HudSelect, { type HudSelectOption } from '@/components/ui/HudSelect.vue';
import HudToggle from '@/components/ui/HudToggle.vue';
import { isSpeechRecognitionSupported } from '@/lib/voice/SpeechRecognizer';
import { DEFAULT_PREFERENCES, usePreferencesStore, type PreferenceUpdate } from '@/stores/preferences';
import { useToastStore } from '@/stores/toast';

const { t, locale } = useI18n();
const preferences = usePreferencesStore();
const toasts = useToastStore();

const speechRecognitionSupported = isSpeechRecognitionSupported();

/**
 * Preferences by section (UI-19): languages, the browser voice fields
 * (`voice_name`, `speech_rate`, `spoken_responses_enabled`,
 * `silence_timeout_ms`, already accepted by `PUT /api/v1/preferences`) and
 * the local wake word with its consent (RF-22).
 */
const form = reactive({
    locale: DEFAULT_PREFERENCES.locale,
    speechLocale: DEFAULT_PREFERENCES.speech_locale,
    voiceName: DEFAULT_PREFERENCES.voice_name,
    speechRate: DEFAULT_PREFERENCES.speech_rate,
    spokenResponsesEnabled: DEFAULT_PREFERENCES.spoken_responses_enabled,
    silenceTimeoutMs: DEFAULT_PREFERENCES.silence_timeout_ms,
    wakeWordEnabled: DEFAULT_PREFERENCES.wake_word_enabled,
});

/** Section anchors; `#connectors` lives on `ConnectorsPanel` (header `Alt+2`). */
const sections = [
    { anchor: 'preferences', label: 'settings.sections.preferences' },
    { anchor: 'voice', label: 'settings.sections.voice' },
    { anchor: 'connectors', label: 'settings.sections.connectors' },
    { anchor: 'install', label: 'settings.sections.install' },
] as const;

/** `HudSelect` reserves `''` for its placeholder, so the browser default (`null`) needs its own value. */
const BROWSER_DEFAULT_VOICE = '__browser_default__';

/** Browser voices (`speechSynthesis.getVoices()`, filled asynchronously via `voiceschanged`). */
const browserVoices = ref<SpeechSynthesisVoice[]>([]);

function readVoices(): void {
    browserVoices.value = 'speechSynthesis' in window ? window.speechSynthesis.getVoices() : [];
}

/** "Padrão do navegador" + the voices for the speech language; the saved voice stays listed even if absent. */
const voiceOptions = computed<HudSelectOption[]>(() => {
    const language = form.speechLocale.toLowerCase();
    const names = browserVoices.value
        .filter((voice) => voice.lang.replace('_', '-').toLowerCase().startsWith(language))
        .map((voice) => voice.name);

    if (form.voiceName !== null && !names.includes(form.voiceName)) {
        names.unshift(form.voiceName);
    }

    return [
        { value: BROWSER_DEFAULT_VOICE, label: t('settings.fields.voiceDefault') },
        ...[...new Set(names)].map((name) => ({ value: name, label: name })),
    ];
});

const voiceSelection = computed({
    get: () => form.voiceName ?? BROWSER_DEFAULT_VOICE,
    set: (value: string) => {
        form.voiceName = value === BROWSER_DEFAULT_VOICE || value === '' ? null : value;
    },
});

const rateFormatter = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const consentOpen = ref(false);
/** The stored consent covers the current consent text (RF-22). */
const consentValid = ref(false);
/** A consent exists but predates the current text: it must be accepted again (RF-22). */
const consentOutdated = ref(false);
const saving = ref(false);
const loading = ref(true);
/** When the operator accepted the wake word consent (`wake_word_consented_at`). */
const consentedAt = ref<string | null>(null);

const consentedAtLabel = computed(() => {
    if (consentedAt.value === null) {
        return null;
    }

    const date = new Date(consentedAt.value);

    return Number.isNaN(date.getTime())
        ? null
        : t('settings.wakeWordConsentedAt', { date: new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(date) });
});

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
        voice_name: form.voiceName,
        speech_rate: form.speechRate,
        spoken_responses_enabled: form.spokenResponsesEnabled,
        silence_timeout_ms: form.silenceTimeoutMs,
        wake_word_enabled: form.wakeWordEnabled,
        // The backend demands the consent flag on every payload that enables the wake word.
        wake_word_consent: form.wakeWordEnabled || consentAccepted ? true : undefined,
    };

    try {
        const saved = await preferences.update(patch);

        consentValid.value = saved.wake_word_consent_valid;
        consentedAt.value = saved.wake_word_consented_at;
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
    readVoices();

    if ('speechSynthesis' in window) {
        window.speechSynthesis.addEventListener('voiceschanged', readVoices);
    }

    const preference = await preferences.ensureLoaded();

    form.locale = preference.locale;
    form.speechLocale = preference.speech_locale;
    form.voiceName = preferences.voiceName;
    form.speechRate = preferences.speechRate;
    form.spokenResponsesEnabled = preferences.spokenResponsesEnabled;
    form.silenceTimeoutMs = preferences.silenceTimeoutMs;
    form.wakeWordEnabled = preference.wake_word_enabled;
    consentedAt.value = preference.wake_word_consented_at;
    consentValid.value = preference.wake_word_consent_valid === true;
    consentOutdated.value = !consentValid.value && preference.wake_word_consented_at !== null;

    // Apply the persisted interface language on load.
    locale.value = preference.locale;

    loading.value = false;
});

onBeforeUnmount(() => {
    if ('speechSynthesis' in window) {
        window.speechSynthesis.removeEventListener('voiceschanged', readVoices);
    }
});
</script>

<template>
    <div class="flex flex-col gap-4 md:flex-row md:items-start" data-testid="settings-page">
        <nav
            class="glass chamfer-md flex shrink-0 gap-1 overflow-x-auto p-2 md:sticky md:top-0 md:w-52 md:flex-col"
            :aria-label="t('pages.settings')"
            data-testid="settings-nav"
        >
            <a
                v-for="section in sections"
                :key="section.anchor"
                :href="`#${section.anchor}`"
                class="label-caps chamfer-sm flex min-h-11 shrink-0 items-center px-3 text-xs text-text-400 transition-colors hover:bg-cyan-300/10 hover:text-biolum-200"
                :data-testid="`settings-nav-${section.anchor}`"
            >
                {{ t(section.label) }}
            </a>
        </nav>

        <div class="flex min-w-0 flex-1 flex-col gap-4">
            <HudPanel id="preferences" :title="t('settings.sections.preferences')" data-testid="settings-section-preferences">
                <div class="flex flex-col gap-3">
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

                    <p class="text-xs text-text-400">{{ t('settings.localeHint') }}</p>
                </div>
            </HudPanel>

            <HudPanel id="voice" :title="t('settings.sections.voice')" data-testid="settings-section-voice">
                <div class="flex flex-col gap-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <HudSelect
                            v-model="voiceSelection"
                            :label="t('settings.fields.voiceName')"
                            :options="voiceOptions"
                            :disabled="loading"
                            data-testid="settings-voice"
                        />

                        <label class="flex min-w-0 flex-col gap-1" data-testid="settings-rate">
                            <span class="label-caps flex items-center justify-between text-[0.65rem] text-text-400">
                                {{ t('settings.fields.speechRate') }}
                                <span class="font-mono text-xs text-cyan-300" data-testid="settings-rate-value">{{ rateFormatter.format(form.speechRate) }}</span>
                            </span>
                            <input
                                v-model.number="form.speechRate"
                                type="range"
                                min="0.5"
                                max="2"
                                step="0.1"
                                class="min-h-11 w-full accent-cyan-300"
                                :disabled="loading"
                            />
                        </label>

                        <label class="flex min-w-0 flex-col gap-1" data-testid="settings-silence">
                            <span class="label-caps flex items-center justify-between text-[0.65rem] text-text-400">
                                {{ t('settings.fields.silenceTimeout') }}
                                <span class="font-mono text-xs text-cyan-300" data-testid="settings-silence-value">{{ form.silenceTimeoutMs }}</span>
                            </span>
                            <input
                                v-model.number="form.silenceTimeoutMs"
                                type="range"
                                min="500"
                                max="5000"
                                step="100"
                                class="min-h-11 w-full accent-cyan-300"
                                :disabled="loading"
                            />
                        </label>

                        <HudToggle
                            v-model="form.spokenResponsesEnabled"
                            :label="t('settings.fields.spokenResponses')"
                            :disabled="loading"
                            class="self-end"
                            data-testid="settings-spoken"
                        />
                    </div>

                    <div class="flex flex-col gap-1 border-t border-ocean-600/70 pt-3">
                        <HudToggle
                            :model-value="form.wakeWordEnabled"
                            :label="t('settings.wakeWord')"
                            :disabled="!speechRecognitionSupported"
                            data-testid="settings-wake-word"
                            @update:model-value="onWakeWordChange"
                        />
                        <p class="text-xs text-text-400">{{ t('settings.wakeWordHint') }}</p>
                        <p v-if="consentValid && consentedAtLabel" class="font-mono text-[0.65rem] text-text-400" data-testid="settings-consented-at">
                            {{ consentedAtLabel }}
                        </p>
                        <div
                            v-if="consentOutdated"
                            class="chamfer-sm flex flex-wrap items-center justify-between gap-2 border border-amber-400/60 bg-amber-400/5 px-3 py-2"
                            role="alert"
                            data-testid="settings-reconsent"
                        >
                            <p class="text-xs text-amber-400">{{ t('settings.wakeWordReconsentBody') }}</p>
                            <HudButton variant="ghost" data-testid="settings-reconsent-review" @click="consentOpen = true">
                                {{ t('settings.wakeWordReconsentAction') }}
                            </HudButton>
                        </div>
                        <p
                            v-if="!speechRecognitionSupported"
                            class="text-xs text-amber-400"
                            role="note"
                            data-testid="settings-wake-word-unsupported"
                        >
                            {{ t('settings.wakeWordUnsupported') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-ocean-600/70 pt-3">
                        <HudButton variant="primary" :loading="saving" :disabled="loading" data-testid="settings-save" @click="save()">
                            {{ t('settings.save') }}
                        </HudButton>
                    </div>
                </div>
            </HudPanel>

            <section class="flex min-w-0 flex-col gap-2" data-testid="settings-section-connectors">
                <ConnectorsPanel />
            </section>

            <section id="install" class="flex min-w-0 flex-col gap-2" data-testid="settings-section-install">
                <h2 class="label-caps text-xs text-text-400">{{ t('settings.sections.install') }}</h2>
                <InstallAppPanel />
            </section>
        </div>

        <HudModal v-model:open="consentOpen" :title="consentTitle" data-testid="settings-consent-modal">
            <p class="text-sm text-text-100" data-testid="settings-consent-body">{{ t('settings.wakeWordConsentBody') }}</p>
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
