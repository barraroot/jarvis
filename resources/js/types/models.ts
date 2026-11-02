export interface UserPreference {
    locale: string;
    speech_locale: string;
    voice_name: string | null;
    speech_rate: number;
    spoken_responses_enabled: boolean;
    wake_word_enabled: boolean;
    wake_word_consented_at: string | null;
    silence_timeout_ms: number;
    extra: Record<string, unknown> | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    last_login_at: string | null;
    preference?: UserPreference;
}

export interface LoginCredentials {
    email: string;
    password: string;
    remember?: boolean;
}
