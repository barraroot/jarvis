import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { API_PREFIX, http } from '@/lib/http';
import type {
    WhatsappConsent,
    WhatsappSchedule,
    WhatsappScope,
    WhatsappSession,
    WhatsappSessionConsent,
    WhatsappSessionStatusChange,
} from '@/types/models';

interface WhatsappSessionEnvelope {
    data: WhatsappSession | null;
}

interface WhatsappConsentEnvelope {
    data: WhatsappConsent;
}

interface WhatsappQrEnvelope {
    data: { qr: string };
}

interface WhatsappPairingCodeEnvelope {
    data: { code: string };
}

interface WhatsappScheduleListEnvelope {
    data: WhatsappSchedule[];
}

interface WhatsappScheduleEnvelope {
    data: WhatsappSchedule;
}

/** Consent text version shipped with this build; the session's `current_consent_version` wins when known. */
export const WHATSAPP_CONSENT_VERSION = 1;

export const WHATSAPP_SCOPES: readonly WhatsappScope[] = ['leitura', 'envio', 'grupos', 'midia'];

const PAIRABLE_STATUSES = ['pending', 'disconnected'];

/**
 * WhatsApp connector (UI-01..UI-07): consent (CT-01/CT-02), the session and
 * its pairing (CT-03..CT-07), schedules (CT-08), kept live by
 * `whatsapp.session.status.changed` (CT-10). The QR and the pairing code only
 * live in memory — never in localStorage/sessionStorage (RNF-10).
 */
export const useWhatsappStore = defineStore('whatsapp', () => {
    const session = ref<WhatsappSession | null>(null);
    const consent = ref<WhatsappSessionConsent | null>(null);
    const schedules = ref<WhatsappSchedule[]>([]);
    const qr = ref<string | null>(null);
    const pairingCode = ref<string | null>(null);
    const loading = ref(false);
    const loaded = ref(false);
    const schedulesLoaded = ref(false);

    const currentConsentVersion = computed(() => session.value?.current_consent_version ?? WHATSAPP_CONSENT_VERSION);
    const hasValidConsent = computed(() => consent.value?.valid === true);
    const canPair = computed(
        () =>
            session.value !== null &&
            !session.value.suspended &&
            PAIRABLE_STATUSES.includes(session.value.status) &&
            hasValidConsent.value,
    );

    function clearPairing(): void {
        qr.value = null;
        pairingCode.value = null;
    }

    function setSession(next: WhatsappSession | null): void {
        session.value = next;

        if (next !== null) {
            consent.value = next.consent;
        }

        if (next === null || next.suspended || !PAIRABLE_STATUSES.includes(next.status)) {
            clearPairing();
        }
    }

    function upsertSchedule(schedule: WhatsappSchedule): void {
        const index = schedules.value.findIndex((candidate) => candidate.id === schedule.id);

        if (index === -1) {
            schedules.value.push(schedule);
        } else {
            schedules.value.splice(index, 1, schedule);
        }
    }

    /** CT-03 */
    async function load(): Promise<WhatsappSession | null> {
        loading.value = true;

        try {
            const response = await http.get<WhatsappSessionEnvelope>(`${API_PREFIX}/whatsapp/session`);

            setSession(response.data);
            loaded.value = true;

            return response.data;
        } finally {
            loading.value = false;
        }
    }

    /** CT-01: replaces the previous scopes with the accepted ones. */
    async function grantConsent(scopes: WhatsappScope[], version: number = currentConsentVersion.value): Promise<WhatsappConsent> {
        const response = await http.post<WhatsappConsentEnvelope>(`${API_PREFIX}/whatsapp/consent`, { version, scopes });
        const granted = { version: response.data.version, scopes: response.data.scopes, valid: response.data.valid };

        consent.value = granted;

        if (session.value !== null) {
            session.value = { ...session.value, consent: granted };
        }

        return response.data;
    }

    /** CT-02: the session goes `disconnected` server-side, so it is reloaded. */
    async function revokeConsent(): Promise<void> {
        await http.delete(`${API_PREFIX}/whatsapp/consent`);

        consent.value = consent.value === null ? null : { ...consent.value, valid: false };
        clearPairing();

        await load();
    }

    /** CT-04: creates the session or retries one in `error`. */
    async function provision(): Promise<WhatsappSession> {
        const response = await http.post<{ data: WhatsappSession }>(`${API_PREFIX}/whatsapp/session`);

        setSession(response.data);

        return response.data;
    }

    /** CT-05 */
    async function requestQr(): Promise<string> {
        const response = await http.post<WhatsappQrEnvelope>(`${API_PREFIX}/whatsapp/session/qr`);

        pairingCode.value = null;
        qr.value = response.data.qr;

        return response.data.qr;
    }

    /** CT-06 */
    async function pairPhone(phone: string): Promise<string> {
        const response = await http.post<WhatsappPairingCodeEnvelope>(`${API_PREFIX}/whatsapp/session/pair-phone`, { phone });

        qr.value = null;
        pairingCode.value = response.data.code;

        return response.data.code;
    }

    /** CT-07: the session, its messages and schedules are gone; consent stays. */
    async function remove(): Promise<void> {
        await http.delete(`${API_PREFIX}/whatsapp/session`);

        setSession(null);
        schedules.value = [];
    }

    /** CT-08 */
    async function loadSchedules(): Promise<WhatsappSchedule[]> {
        const response = await http.get<WhatsappScheduleListEnvelope>(`${API_PREFIX}/whatsapp/schedules`);

        schedules.value = response.data;
        schedulesLoaded.value = true;

        return response.data;
    }

    /** CT-08 cancel */
    async function cancelSchedule(id: number): Promise<WhatsappSchedule> {
        const response = await http.post<WhatsappScheduleEnvelope>(`${API_PREFIX}/whatsapp/schedules/${id}/cancel`);

        upsertSchedule(response.data);

        return response.data;
    }

    /** CT-08 resume */
    async function resumeSchedule(id: number): Promise<WhatsappSchedule> {
        const response = await http.post<WhatsappScheduleEnvelope>(`${API_PREFIX}/whatsapp/schedules/${id}/resume`);

        upsertSchedule(response.data);

        return response.data;
    }

    /** CT-10: patch the known session (hiding QR/code once it leaves pairing); unknown ones trigger a reload. */
    function applyStatusChange(change: WhatsappSessionStatusChange): void {
        if (session.value === null || session.value.id !== change.session_id) {
            void load().catch(() => undefined);

            return;
        }

        setSession({
            ...session.value,
            status: change.status,
            suspended: change.suspended,
            last_error: change.error,
            connected_at: change.status === 'connected' ? change.at : session.value.connected_at,
        });
    }

    function reset(): void {
        session.value = null;
        consent.value = null;
        schedules.value = [];
        clearPairing();
        loaded.value = false;
        schedulesLoaded.value = false;
    }

    return {
        session,
        consent,
        schedules,
        qr,
        pairingCode,
        loading,
        loaded,
        schedulesLoaded,
        currentConsentVersion,
        hasValidConsent,
        canPair,
        load,
        grantConsent,
        revokeConsent,
        provision,
        requestQr,
        pairPhone,
        remove,
        loadSchedules,
        cancelSchedule,
        resumeSchedule,
        applyStatusChange,
        clearPairing,
        reset,
    };
});
