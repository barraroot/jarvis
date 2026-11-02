import { translate } from '@/i18n';
import { redirectToLogin } from '@/lib/navigation';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

export const API_PREFIX = '/api/v1';

const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';
const XSRF_COOKIE_NAME = 'XSRF-TOKEN';
const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

export type ValidationErrors = Record<string, string[]>;

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
    headers?: Record<string, string>;
    signal?: AbortSignal;
    /** Skip the global 401 handler (store reset + redirect), e.g. for the boot `me` probe. */
    skipUnauthorizedHandler?: boolean;
    /** Skip the global 429 toast, e.g. when the caller renders its own countdown. */
    skipTooManyRequestsHandler?: boolean;
}

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        public readonly data: unknown,
        public readonly retryAfter: number | null = null,
    ) {
        super(`HTTP ${status}`);
        this.name = 'HttpError';
    }

    get validationErrors(): ValidationErrors {
        if (this.status !== 422 || typeof this.data !== 'object' || this.data === null) {
            return {};
        }

        const errors = (this.data as { errors?: unknown }).errors;

        return typeof errors === 'object' && errors !== null ? (errors as ValidationErrors) : {};
    }

    get serverMessage(): string | null {
        if (typeof this.data !== 'object' || this.data === null) {
            return null;
        }

        const message = (this.data as { message?: unknown }).message;

        return typeof message === 'string' ? message : null;
    }
}

let csrfRequest: Promise<void> | null = null;

function readCookie(name: string): string | null {
    const match = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

/**
 * Fetches the Sanctum CSRF cookie once per page lifetime (reset after a 419).
 */
export function ensureCsrfCookie(): Promise<void> {
    if (csrfRequest === null) {
        csrfRequest = fetch(CSRF_COOKIE_URL, {
            credentials: 'include',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(
            () => undefined,
            (error: unknown) => {
                csrfRequest = null;
                throw error;
            },
        );
    }

    return csrfRequest;
}

export function resetCsrfCookie(): void {
    csrfRequest = null;
}

function parseRetryAfter(header: string | null): number | null {
    if (header === null) {
        return null;
    }

    const seconds = Number.parseInt(header, 10);

    if (!Number.isNaN(seconds)) {
        return Math.max(0, seconds);
    }

    const date = Date.parse(header);

    return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000));
}

async function parseBody(response: Response): Promise<unknown> {
    if (response.status === 204) {
        return null;
    }

    const text = await response.text();

    if (text === '') {
        return null;
    }

    try {
        return JSON.parse(text) as unknown;
    } catch {
        return text;
    }
}

async function send(url: string, options: RequestOptions, retriedCsrf: boolean): Promise<Response> {
    const method = options.method ?? 'GET';

    if (!SAFE_METHODS.includes(method)) {
        await ensureCsrfCookie();
    }

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    const xsrfToken = readCookie(XSRF_COOKIE_NAME);

    if (xsrfToken !== null) {
        headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    let body: BodyInit | undefined;

    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body);
    }

    const response = await fetch(url, { method, headers, body, credentials: 'include', signal: options.signal });

    if (response.status === 419 && !retriedCsrf) {
        resetCsrfCookie();

        return send(url, options, true);
    }

    return response;
}

/**
 * JSON client for the Laravel API: cookie credentials, Sanctum CSRF, global 401/429 handling.
 */
export async function request<T = unknown>(url: string, options: RequestOptions = {}): Promise<T> {
    const response = await send(url, options, false);
    const data = await parseBody(response);

    if (response.ok) {
        return data as T;
    }

    const error = new HttpError(response.status, data, parseRetryAfter(response.headers.get('Retry-After')));

    if (response.status === 401 && !options.skipUnauthorizedHandler) {
        useAuthStore().clear();
        await redirectToLogin();
    }

    if (response.status === 429 && !options.skipTooManyRequestsHandler) {
        useToastStore().push(translate('errors.tooManyRequests', { seconds: error.retryAfter ?? 60 }), {
            variant: 'warning',
        });
    }

    throw error;
}

export const http = {
    get: <T = unknown>(url: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
        request<T>(url, { ...options, method: 'GET' }),
    post: <T = unknown>(url: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
        request<T>(url, { ...options, method: 'POST', body }),
    put: <T = unknown>(url: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
        request<T>(url, { ...options, method: 'PUT', body }),
    patch: <T = unknown>(url: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
        request<T>(url, { ...options, method: 'PATCH', body }),
    delete: <T = unknown>(url: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) =>
        request<T>(url, { ...options, method: 'DELETE' }),
};
