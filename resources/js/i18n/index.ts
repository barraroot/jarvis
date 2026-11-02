import { createI18n } from 'vue-i18n';
import ptBR from '@/i18n/pt-BR.json';

export type MessageSchema = typeof ptBR;

export const DEFAULT_LOCALE = 'pt-BR';

export function createAppI18n() {
    return createI18n<[MessageSchema], 'pt-BR', false>({
        legacy: false,
        locale: DEFAULT_LOCALE,
        fallbackLocale: DEFAULT_LOCALE,
        messages: { 'pt-BR': ptBR },
    });
}

export const i18n = createAppI18n();

/**
 * Translate outside components (stores, HTTP layer).
 */
export function translate(key: string, named: Record<string, unknown> = {}): string {
    return i18n.global.t(key, named);
}
