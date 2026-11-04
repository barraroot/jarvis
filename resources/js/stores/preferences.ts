import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { translate } from '@/i18n';
import { API_PREFIX, HttpError, http } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import type { UserPreference } from '@/types/models';

/** Patch accepted by `PUT /api/v1/preferences` (US-4.5, US-4.4). */
export interface PreferenceUpdate {
    locale?: string;
    speech_locale?: string;
    voice_name?: string | null;
    speech_rate?: number;
    spoken_responses_enabled?: boolean;
    wake_word_enabled?: boolean;
    /** Privacy consent gate for the wake word; not a stored column. */
    wake_word_consent?: boolean;
    silence_timeout_ms?: number;
    extra?: Record<string, unknown> | null;
}

interface PreferenceEnvelope {
    data: UserPreference;
}

export const DEFAULT_PREFERENCES: UserPreference = {
    locale: 'pt-BR',
    speech_locale: 'pt-BR',
    voice_name: null,
    speech_rate: 1,
    spoken_responses_enabled: true,
    wake_word_enabled: false,
    wake_word_consented_at: null,
    wake_word_consent_valid: false,
    silence_timeout_ms: 1500,
    extra: null,
};

/**
 * The operator's persisted preferences (US-4.5). Seeded from the `GET
 * /auth/me` envelope when it carries a preference, otherwise fetched from
 * `GET /api/v1/preferences` on first use.
 */
export const usePreferencesStore = defineStore('preferences', () => {
    const preference = ref<UserPreference | null>(null);
    const loaded = ref(false);
    const saving = ref(false);
    let pendingLoad: Promise<UserPreference> | null = null;

    const locale = computed(() => preference.value?.locale ?? DEFAULT_PREFERENCES.locale);
    const speechLocale = computed(() => preference.value?.speech_locale ?? DEFAULT_PREFERENCES.speech_locale);
    const voiceName = computed(() => preference.value?.voice_name ?? null);
    const speechRate = computed(() => preference.value?.speech_rate ?? DEFAULT_PREFERENCES.speech_rate);
    const spokenResponsesEnabled = computed(() => preference.value?.spoken_responses_enabled ?? DEFAULT_PREFERENCES.spoken_responses_enabled);
    const wakeWordEnabled = computed(() => preference.value?.wake_word_enabled ?? DEFAULT_PREFERENCES.wake_word_enabled);
    /** A consent given for an older consent text is invalid and must be accepted again (RF-22). */
    const wakeWordConsentValid = computed(() => preference.value?.wake_word_consent_valid ?? DEFAULT_PREFERENCES.wake_word_consent_valid);
    const silenceTimeoutMs = computed(() => preference.value?.silence_timeout_ms ?? DEFAULT_PREFERENCES.silence_timeout_ms);

    async function fetchPreferences(): Promise<UserPreference> {
        const fromUser = useAuthStore().user?.preference;

        if (fromUser !== undefined) {
            preference.value = fromUser;
            loaded.value = true;

            return fromUser;
        }

        const response = await http.get<PreferenceEnvelope>(`${API_PREFIX}/preferences`);
        preference.value = response.data;
        loaded.value = true;

        return response.data;
    }

    /**
     * Loads the preferences once; concurrent callers share the same request.
     * On failure the defaults are returned so voice features stay usable.
     */
    async function ensureLoaded(): Promise<UserPreference> {
        if (preference.value !== null) {
            return preference.value;
        }

        pendingLoad ??= fetchPreferences().finally(() => {
            pendingLoad = null;
        });

        try {
            return await pendingLoad;
        } catch {
            return { ...DEFAULT_PREFERENCES };
        }
    }

    async function update(patch: PreferenceUpdate): Promise<UserPreference> {
        await ensureLoaded();
        saving.value = true;

        try {
            const response = await http.put<PreferenceEnvelope>(`${API_PREFIX}/preferences`, patch);
            preference.value = response.data;
            loaded.value = true;

            return response.data;
        } catch (error) {
            if (error instanceof HttpError && error.status === 422 && patch.wake_word_enabled === true) {
                useToastStore().push(translate('settings.wakeWordConsentRequired'), { variant: 'warning' });
            } else {
                useToastStore().push(translate('errors.network'), { variant: 'danger' });
            }

            throw error;
        } finally {
            saving.value = false;
        }
    }

    return {
        preference,
        loaded,
        saving,
        locale,
        speechLocale,
        voiceName,
        speechRate,
        spokenResponsesEnabled,
        wakeWordEnabled,
        wakeWordConsentValid,
        silenceTimeoutMs,
        ensureLoaded,
        update,
    };
});
