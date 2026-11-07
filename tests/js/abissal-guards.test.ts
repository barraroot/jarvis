import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { parse } from 'vue/compiler-sfc';
import { describe, expect, it } from 'vitest';

/**
 * Repository-wide guards for the Abissal layout (T23): once the legacy aliases
 * are gone, these keep rounded corners, illustrative literals, the previous
 * palette, non-compositor infinite animations and new dependencies from
 * creeping back in.
 */

const ROOT = process.cwd();
const SOURCE_DIRECTORY = join(ROOT, 'resources/js');
const APP_CSS = readFileSync(join(ROOT, 'resources/css/app.css'), 'utf8');

interface TemplateNode {
    type: number;
    tag?: string;
    children?: TemplateNode[];
    props?: Array<{
        type: number;
        name: string;
        value?: { content: string };
        arg?: { content?: string };
        exp?: { content?: string };
    }>;
    loc: { start: { line: number } };
}

function sourceFiles(directory: string, extensions: string[]): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            return sourceFiles(path, extensions);
        }

        return extensions.some((extension) => path.endsWith(extension)) ? [path] : [];
    });
}

const VUE_FILES = sourceFiles(SOURCE_DIRECTORY, ['.vue']);
const SOURCE_FILES = sourceFiles(SOURCE_DIRECTORY, ['.vue', '.ts']);

function display(path: string): string {
    return relative(ROOT, path);
}

/** Drops block and line comments so prose about the rules does not trip them. */
function stripComments(source: string): string {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
}

/** Every `rounded*` utility except the allowed `rounded-none` and the dot-only `rounded-full`. */
const ROUNDED_UTILITY = /(?<![-\w])rounded(?:-(?!none(?![-\w])|full(?![-\w]))[\w[\].]+)*(?![-\w])/g;
const ROUNDED_FULL = /(?<![-\w])rounded-full(?![-\w])/;

/** Static `class` plus every `:class` expression of an element. */
function classSources(node: TemplateNode): string[] {
    return (node.props ?? []).flatMap((prop) => {
        // NodeTypes.ATTRIBUTE === 6, NodeTypes.DIRECTIVE === 7
        if (prop.type === 6 && prop.name === 'class') {
            return [prop.value?.content ?? ''];
        }

        if (prop.type === 7 && prop.name === 'bind' && prop.arg?.content === 'class') {
            return [prop.exp?.content ?? ''];
        }

        return [];
    });
}

function hasDataDot(node: TemplateNode): boolean {
    return (node.props ?? []).some(
        (prop) => (prop.type === 6 && prop.name === 'data-dot') || (prop.type === 7 && prop.arg?.content === 'data-dot'),
    );
}

function roundedViolations(node: TemplateNode, found: string[]): void {
    // NodeTypes.ELEMENT === 1
    if (node.type === 1) {
        for (const classes of classSources(node)) {
            for (const match of classes.matchAll(ROUNDED_UTILITY)) {
                found.push(`line ${node.loc.start.line}: ${match[0]}`);
            }

            if (ROUNDED_FULL.test(classes) && !hasDataDot(node)) {
                found.push(`line ${node.loc.start.line}: rounded-full without data-dot on <${node.tag}>`);
            }
        }
    }

    node.children?.forEach((child) => roundedViolations(child, found));
}

/** `@keyframes name { … }` bodies, matched by brace depth. */
function keyframes(source: string): Map<string, string[]> {
    const found = new Map<string, string[]>();

    for (const match of source.matchAll(/@keyframes\s+([\w-]+)\s*\{/g)) {
        let depth = 1;
        let index = match.index + match[0].length;

        while (depth > 0 && index < source.length) {
            depth += source[index] === '{' ? 1 : source[index] === '}' ? -1 : 0;
            index++;
        }

        const name = match[1]!;
        found.set(name, [...(found.get(name) ?? []), source.slice(match.index + match[0].length, index - 1)]);
    }

    return found;
}

/** Keyframe names used by an animation with `iteration-count: infinite`. */
function infiniteAnimationNames(source: string, known: Set<string>): string[] {
    const names: string[] = [];

    for (const [, value] of source.matchAll(/(?:animation|--animate-[\w-]+)\s*:\s*([^;{}]+)/g)) {
        for (const segment of value!.split(/,(?![^(]*\))/)) {
            if (/(?<![-\w])infinite(?![-\w])/.test(segment)) {
                names.push(...(segment.match(/[\w-]+/g) ?? []).filter((word) => known.has(word)));
            }
        }
    }

    for (const [, block] of source.matchAll(/\{([^{}]*)\}/g)) {
        if (/animation-iteration-count\s*:\s*infinite/.test(block!)) {
            names.push(...(block!.match(/animation-name\s*:\s*([^;]+)/)?.[1]?.split(',').map((name) => name.trim()) ?? []));
        }
    }

    return names;
}

/** Properties a compositor-friendly infinite animation may touch (RNF-07). */
const INFINITE_SAFE_PROPERTIES = ['transform', 'opacity', 'stroke-dashoffset', 'stroke-dasharray', 'animation-timing-function'];

/** Dependency keys of `package.json` before the Abissal layout started (commit 41daeca). */
const BASELINE_DEPENDENCIES = {
    dependencies: ['dompurify', 'laravel-echo', 'markdown-it', 'pinia', 'pusher-js', 'vue', 'vue-i18n', 'vue-router'],
    devDependencies: [
        '@tailwindcss/vite',
        '@types/dompurify',
        '@types/markdown-it',
        '@vitejs/plugin-vue',
        '@vue/test-utils',
        'concurrently',
        'happy-dom',
        'laravel-vite-plugin',
        'tailwindcss',
        'typescript',
        'vite',
        'vite-plugin-pwa',
        'vitest',
        'vue-tsc',
    ],
    optionalDependencies: ['@laravel/multiplex'],
} as const;

describe('Abissal guards', () => {
    it('(a) uses no rounded corners outside JarvisCore, and rounded-full only on data-dot elements (UI-01)', () => {
        const violations = VUE_FILES.filter((path) => !path.endsWith('JarvisCore.vue')).flatMap((path) => {
            const { descriptor } = parse(readFileSync(path, 'utf8'), { filename: path });
            const found: string[] = [];

            if (descriptor.template) {
                roundedViolations(descriptor.template.ast as unknown as TemplateNode, found);
            }

            const code = [descriptor.script?.content, descriptor.scriptSetup?.content, ...descriptor.styles.map((style) => style.content)]
                .filter((content): content is string => content !== undefined)
                .map(stripComments)
                .join('\n');

            for (const match of code.matchAll(new RegExp(`${ROUNDED_UTILITY.source}|${ROUNDED_FULL.source}`, 'g'))) {
                found.push(`script/style: ${match[0]}`);
            }

            return found.map((violation) => `${display(path)} ${violation}`);
        });

        expect(violations).toEqual([]);
    });

    it('(b) ships no illustrative literals from the boards (RF-04)', () => {
        const literals = ['gpt-5', 'Beer And Code', 'R$ 120', 'Risco médio', 'dra-daniele', 'v0.9.2'];

        const violations = SOURCE_FILES.flatMap((path) => {
            const source = readFileSync(path, 'utf8');

            return literals.filter((literal) => source.includes(literal)).map((literal) => `${display(path)}: ${literal}`);
        });

        expect(violations).toEqual([]);
    });

    it('(c) uses no legacy color utilities, variables or hud-* utilities', () => {
        const legacy = [
            /(bg|text|border|ring|fill|stroke|shadow|from|to|via|divide|outline)-(bg|bg-navy|panel|line|cyan|cyan-bright|blue|purple|amber|danger|success|text|muted)(?![-\w])/g,
            /hud-(glass|border|glow|grid|label)(?![-\w])/g,
            /var\(--color-(bg|bg-navy|panel|line|cyan|cyan-bright|blue|purple|amber|danger|success|text|muted)\)/g,
            /var\(--(font-display|tracking-hud)\)/g,
        ];

        const violations = [...SOURCE_FILES, join(ROOT, 'resources/css/app.css')].flatMap((path) => {
            const source = readFileSync(path, 'utf8');

            return legacy.flatMap((pattern) => [...source.matchAll(pattern)].map((match) => `${display(path)}: ${match[0]}`));
        });

        expect(violations).toEqual([]);
    });

    it('(d) drops the previous palette and font from app.css (RF-01)', () => {
        expect(APP_CSS.toLowerCase()).not.toContain('#050a12');
        expect(APP_CSS.toLowerCase()).not.toContain('#22d3ee');
        expect(APP_CSS).not.toContain('Exo 2');
    });

    it('(e) animates infinite keyframes only with transform, opacity and stroke dashes (RNF-07)', () => {
        const sources = [
            { path: 'resources/css/app.css', css: stripComments(APP_CSS) },
            ...VUE_FILES.flatMap((path) =>
                parse(readFileSync(path, 'utf8'), { filename: path }).descriptor.styles.map((style) => ({
                    path: display(path),
                    css: stripComments(style.content),
                })),
            ),
        ];

        const bodies = new Map<string, string[]>();

        for (const { css } of sources) {
            for (const [name, found] of keyframes(css)) {
                bodies.set(name, [...(bodies.get(name) ?? []), ...found]);
            }
        }

        const infinite = new Set(sources.flatMap(({ css }) => infiniteAnimationNames(css, new Set(bodies.keys()))));

        expect(infinite).toContain('core-spin');
        expect(infinite).toContain('abyss-caustics');

        const violations = [...infinite].flatMap((name) =>
            (bodies.get(name) ?? []).flatMap((body) =>
                [...body.matchAll(/([\w-]+)\s*:/g)]
                    .map((match) => match[1]!)
                    .filter((property) => !INFINITE_SAFE_PROPERTIES.includes(property))
                    .map((property) => `@keyframes ${name}: ${property}`),
            ),
        );

        expect(violations).toEqual([]);
    });

    it('(f) keeps package.json dependencies identical to the baseline (RNF-03)', () => {
        const manifest = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')) as Record<string, Record<string, string> | undefined>;

        for (const [group, baseline] of Object.entries(BASELINE_DEPENDENCIES)) {
            expect(Object.keys(manifest[group] ?? {}).sort(), group).toEqual([...baseline].sort());
        }
    });
});
