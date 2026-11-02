import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { API_PREFIX, HttpError, http, resetCsrfCookie } from '@/lib/http';
import { redirectToLogin } from '@/lib/navigation';
import { useNotificationsStore } from '@/stores/notifications';
import type { LoginCredentials, User } from '@/types/models';

interface UserEnvelope {
    data: User;
}

/** Statuses a proxy/worker answers with while the Laravel backend is unreachable. */
const UNREACHABLE_STATUSES = [502, 503, 504];

/**
 * Network failure (offline, DNS, the service worker's NetworkOnly rejecting) or a gateway error:
 * the session state is unknown, not "signed out".
 */
export function isServerUnreachable(error: unknown): boolean {
    return !(error instanceof HttpError) || UNREACHABLE_STATUSES.includes(error.status);
}

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null);
    const loaded = ref(false);
    /** Boot could not reach the server: the PWA shell runs DISCONNECTED until the probe succeeds (US-2.5). */
    const unreachable = ref(false);
    let pendingMe: Promise<User | null> | null = null;

    const isAuthenticated = computed(() => user.value !== null);

    function clear(): void {
        user.value = null;
        unreachable.value = false;
        loaded.value = true;
    }

    async function fetchMe(): Promise<User | null> {
        try {
            const response = await http.get<UserEnvelope>(`${API_PREFIX}/auth/me`, { skipUnauthorizedHandler: true });
            user.value = response.data;
            unreachable.value = false;
            useNotificationsStore().setUnreadCount(response.data.notifications_unread ?? 0);
        } catch (error) {
            if (isServerUnreachable(error)) {
                unreachable.value = true;
            }

            if (!(error instanceof HttpError) || (error.status !== 401 && error.status !== 403)) {
                throw error;
            }

            user.value = null;
            unreachable.value = false;
        } finally {
            loaded.value = true;
        }

        return user.value;
    }

    /**
     * Loads the session user once (boot); concurrent callers share the same request.
     */
    async function ensureLoaded(): Promise<User | null> {
        if (loaded.value) {
            return user.value;
        }

        pendingMe ??= fetchMe().finally(() => {
            pendingMe = null;
        });

        try {
            return await pendingMe;
        } catch {
            return user.value;
        }
    }

    async function login(credentials: LoginCredentials): Promise<User> {
        const response = await http.post<UserEnvelope>(`${API_PREFIX}/auth/login`, credentials, {
            skipTooManyRequestsHandler: true,
        });

        user.value = response.data;
        loaded.value = true;

        return response.data;
    }

    async function logout(): Promise<void> {
        try {
            await http.post(`${API_PREFIX}/auth/logout`, undefined, { skipUnauthorizedHandler: true });
        } catch {
            // The session may already be gone; local state is cleared regardless.
        } finally {
            clear();
            // Session is invalidated server-side, so the CSRF token rotates too.
            resetCsrfCookie();
        }

        await redirectToLogin();
    }

    return { user, loaded, unreachable, isAuthenticated, clear, fetchMe, ensureLoaded, login, logout };
});
