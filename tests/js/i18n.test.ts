import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { parse } from 'vue/compiler-sfc';
import { describe, expect, it } from 'vitest';
import ptBR from '@/i18n/pt-BR.json';

/** Static attributes that are user-visible and therefore must come from `t()`. */
const TRANSLATABLE_ATTRIBUTES = ['title', 'placeholder', 'aria-label', 'alt', 'label'];
const HAS_LETTERS = /\p{L}/u;

interface TemplateNode {
    type: number;
    content?: unknown;
    children?: TemplateNode[];
    props?: Array<{ type: number; name: string; value?: { content: string } }>;
    loc: { start: { line: number } };
}

function vueFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);

        return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
    });
}

function bareStrings(node: TemplateNode, found: string[]): void {
    // NodeTypes.TEXT === 2
    if (node.type === 2 && typeof node.content === 'string' && HAS_LETTERS.test(node.content)) {
        found.push(`line ${node.loc.start.line}: "${node.content.trim()}"`);
    }

    // NodeTypes.ATTRIBUTE === 6
    for (const prop of node.props ?? []) {
        if (prop.type === 6 && TRANSLATABLE_ATTRIBUTES.includes(prop.name) && HAS_LETTERS.test(prop.value?.content ?? '')) {
            found.push(`line ${node.loc.start.line}: ${prop.name}="${prop.value?.content}"`);
        }
    }

    node.children?.forEach((child) => bareStrings(child, found));
}

describe('i18n', () => {
    it('ships pt-BR messages', () => {
        expect(ptBR.common.noData).toBe('sem dados');
    });

    it('templates have no bare user-facing strings (every text goes through t())', () => {
        const root = join(process.cwd(), 'resources/js');
        const offenders: string[] = [];

        for (const file of vueFiles(root)) {
            const { descriptor } = parse(readFileSync(file, 'utf8'));
            const found: string[] = [];

            if (descriptor.template?.ast) {
                bareStrings(descriptor.template.ast as unknown as TemplateNode, found);
            }

            offenders.push(...found.map((entry) => `${relative(root, file)} ${entry}`));
        }

        expect(offenders).toEqual([]);
    });
});
