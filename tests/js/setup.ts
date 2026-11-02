import { config } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, vi } from 'vitest';
import { i18n } from '@/i18n';

/**
 * happy-dom ships placeholder accessors on the DOM prototypes (e.g.
 * `get nodeName() { return ''; }`) and a `Document#createNodeIterator` whose
 * traversal stops once a node is removed. DOMPurify sniffs the prototypes and
 * walks the DOM with that iterator, so both quirks break sanitization under
 * happy-dom — elements end up stripped or skipped entirely. Real browsers are
 * unaffected; this shim removes the placeholder accessors (property lookup
 * then reaches happy-dom's working getters) and swaps the iterator for a
 * snapshot walk that is immune to mid-traversal removal.
 */
const { NodeFilter: NodeFilterCtor } = globalThis as unknown as { NodeFilter: typeof NodeFilter };

/**
 * happy-dom's `Node.prototype.nodeName` is a placeholder that returns `''`
 * (DOMPurify reads it through its own accessor sniffing and strips every
 * element). Replace it with a spec-compliant getter derived from stable
 * per-instance properties, which happy-dom implements correctly.
 */
Object.defineProperty(Node.prototype, 'nodeName', {
    configurable: true,
    get(this: Node): string {
        switch (this.nodeType) {
            case 1:
                return (this as Element).tagName;
            case 3:
                return '#text';
            case 4:
                return '#cdata-section';
            case 7:
                return (this as ProcessingInstruction).target;
            case 8:
                return '#comment';
            case 9:
                return '#document';
            case 10:
                return (this as DocumentType).name;
            case 11:
                return '#document-fragment';
            default:
                return '';
        }
    },
});

Object.defineProperty(Document.prototype, 'createNodeIterator', {
    configurable: true,
    writable: true,
    value: function createNodeIterator(
        this: Document,
        root: Node,
        whatToShow?: number,
        filter?: ((node: Node) => number) | null,
    ) {
        const nodes: Node[] = [];
        const walk = (node: Node): void => {
            if (whatToShow === undefined || ((1 << (node.nodeType - 1)) & whatToShow) !== 0) {
                nodes.push(node);
            }

            node.childNodes.forEach(walk);
        };
        walk(root);

        let index = -1;

        return {
            nextNode(): Node | null {
                index++;

                while (index < nodes.length) {
                    const node = nodes[index];

                    if (node !== undefined && (filter === undefined || filter === null || filter(node) === NodeFilterCtor.FILTER_ACCEPT)) {
                        return node;
                    }

                    index++;
                }

                return null;
            },
        };
    },
});

beforeEach(() => {
    const pinia = createPinia();

    setActivePinia(pinia);
    config.global.plugins = [i18n, pinia];
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    // Default fetch answers 404 so tests that mount chrome fetching on setup never hit the network.
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify({ message: 'Not Found' }), { status: 404 })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    localStorage.clear();
});
