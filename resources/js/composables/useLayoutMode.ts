import { getCurrentInstance, onBeforeUnmount, readonly, ref, type Ref } from 'vue';

export type LayoutMode = 'mobile' | 'tablet' | 'desktop';

/**
 * Same breakpoints as the Tailwind classes in HudLayout (`md` = 768px, `xl` = 1280px):
 * mobile < 768 ≤ tablet < 1280 ≤ desktop (project-description §3.3).
 */
export const LAYOUT_MEDIA_QUERIES = {
    tablet: '(min-width: 768px) and (max-width: 1279.98px)',
    desktop: '(min-width: 1280px)',
} as const;

function currentMode(tablet: MediaQueryList | null, desktop: MediaQueryList | null): LayoutMode {
    if (desktop?.matches) {
        return 'desktop';
    }

    if (tablet?.matches) {
        return 'tablet';
    }

    // Without matchMedia (very old engines) the CSS breakpoints still apply; desktop is the safe JS default.
    return tablet === null ? 'desktop' : 'mobile';
}

/**
 * Reactive layout mode from `matchMedia` (US-2.1): drives what the CSS breakpoints
 * cannot, e.g. mounting the compact core only on mobile or the default sidebar collapse.
 */
export function useLayoutMode(): { mode: Readonly<Ref<LayoutMode>> } {
    const hasMatchMedia = typeof window !== 'undefined' && typeof window.matchMedia === 'function';
    const tablet = hasMatchMedia ? window.matchMedia(LAYOUT_MEDIA_QUERIES.tablet) : null;
    const desktop = hasMatchMedia ? window.matchMedia(LAYOUT_MEDIA_QUERIES.desktop) : null;

    const mode = ref<LayoutMode>(currentMode(tablet, desktop));

    const update = (): void => {
        mode.value = currentMode(tablet, desktop);
    };

    tablet?.addEventListener('change', update);
    desktop?.addEventListener('change', update);

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(() => {
            tablet?.removeEventListener('change', update);
            desktop?.removeEventListener('change', update);
        });
    }

    return { mode: readonly(mode) };
}
