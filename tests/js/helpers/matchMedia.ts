import { vi } from 'vitest';

type Listener = (event: MediaQueryListEvent) => void;

interface MockMediaQueryList {
    media: string;
    readonly matches: boolean;
    listeners: Set<Listener>;
}

/** Evaluates the `min-width`/`max-width` clauses used by the layout queries. */
function evaluate(query: string, width: number, reducedMotion: boolean): boolean {
    if (query.includes('prefers-reduced-motion')) {
        return reducedMotion;
    }

    const min = /min-width:\s*([\d.]+)px/.exec(query);
    const max = /max-width:\s*([\d.]+)px/.exec(query);

    return (min === null || width >= Number(min[1])) && (max === null || width <= Number(max[1]));
}

/**
 * Stubs `window.matchMedia` for a viewport width; `resize()` fires `change` on every
 * query whose result flips, like a real rotation/resize. `prefers-reduced-motion`
 * matches only when `reducedMotion` is set.
 */
export function mockViewport(initialWidth: number, { reducedMotion = false }: { reducedMotion?: boolean } = {}) {
    let width = initialWidth;
    const lists: MockMediaQueryList[] = [];

    vi.stubGlobal(
        'matchMedia',
        vi.fn((media: string) => {
            const list: MockMediaQueryList & MediaQueryList = {
                media,
                listeners: new Set<Listener>(),
                get matches() {
                    return evaluate(media, width, reducedMotion);
                },
                onchange: null,
                addEventListener: (_type: string, listener: Listener) => list.listeners.add(listener),
                removeEventListener: (_type: string, listener: Listener) => list.listeners.delete(listener),
                addListener: (listener: Listener) => list.listeners.add(listener),
                removeListener: (listener: Listener) => list.listeners.delete(listener),
                dispatchEvent: () => true,
            } as unknown as MockMediaQueryList & MediaQueryList;

            lists.push(list);

            return list;
        }),
    );

    return {
        resize(nextWidth: number): void {
            const before = lists.map((list) => list.matches);
            width = nextWidth;

            lists.forEach((list, index) => {
                if (list.matches !== before[index]) {
                    list.listeners.forEach((listener) => listener({ matches: list.matches, media: list.media } as MediaQueryListEvent));
                }
            });
        },
    };
}
