import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

const COLORS = {
    'abyss-950': '#02060D',
    'abyss-900': '#061321',
    'abyss-800': '#0A2236',
    'ocean-600': '#0E4D7A',
    'ocean-400': '#1F8FD1',
    'cyan-300': '#4FE3F0',
    'biolum-200': '#A6F7FF',
    'amber-400': '#FFB547',
    'success-400': '#3DDC97',
    'danger-400': '#FF5A6E',
    'text-100': '#E6F4FF',
    'text-400': '#7FA3BF',
} as const;

/** Reads `--name: value;` from the stylesheet (first declaration wins). */
function tokenValue(name: string): string | undefined {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return css.match(new RegExp(`${escaped}:\\s*([^;]+);`))?.[1]?.trim();
}

/** WCAG 2.x relative luminance of a #RRGGBB color. */
function luminance(hex: string): number {
    const linear = (offset: number): number => {
        const c = parseInt(hex.slice(offset, offset + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * linear(1) + 0.7152 * linear(3) + 0.0722 * linear(5);
}

function contrastRatio(foreground: string, background: string): number {
    const first = luminance(foreground);
    const second = luminance(background);

    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}

describe('Abissal design tokens', () => {
    it.each(Object.entries(COLORS))('defines --color-%s as %s', (name, hex) => {
        expect(tokenValue(`--color-${name}`)?.toLowerCase()).toBe(hex.toLowerCase());
    });

    it.each([
        ['--chamfer-sm', '8px'],
        ['--chamfer-md', '14px'],
        ['--hit-min', '44px'],
    ])('defines %s as %s', (token, value) => {
        expect(tokenValue(token)).toBe(value);
    });

    it.each([
        ['--dur-fast', '90ms'],
        ['--dur-base', '350ms'],
        ['--dur-fly', '700ms'],
        ['--dur-glow', '1500ms'],
        ['--dur-breathe', '4000ms'],
        ['--stagger', '40ms'],
    ])('defines motion duration %s as %s', (token, value) => {
        expect(tokenValue(token)).toBe(value);
    });

    it.each([
        ['--ease-fly', 'cubic-bezier(0.2, 0.8, 0.2, 1)'],
        ['--ease-curve-y', 'cubic-bezier(0.6, 0, 0.3, 1)'],
        ['--ease-sonar', 'ease-out'],
    ])('defines easing %s as %s', (token, value) => {
        expect(tokenValue(token)).toBe(value);
    });

    it.each([
        ['--text-display-xl', '64px', '1'],
        ['--text-clock', '44px', '1'],
        ['--text-h1', '28px', '1.15'],
        ['--text-caption', '18px', '1.4'],
        ['--text-body', '14.5px', '1.55'],
        ['--text-label', '11px', '1'],
    ])('defines type scale %s as %s/%s', (token, size, lineHeight) => {
        expect(tokenValue(token)).toBe(size);
        expect(tokenValue(`${token}--line-height`)).toBe(lineHeight);
    });

    it('tracks labels at .22em', () => {
        expect(tokenValue('--text-label--letter-spacing')).toBe('0.22em');
    });

    it.each([
        ['--font-logo', 'Orbitron'],
        ['--font-label', 'Rajdhani'],
        ['--font-sans', 'Inter'],
        ['--font-mono', 'JetBrains Mono'],
    ])('defines %s with %s first', (token, family) => {
        expect(tokenValue(token)).toMatch(new RegExp(`^'${family}'`));
    });

    it.each(['glass', 'chamfer-sm', 'chamfer-md', 'glow', 'abyss-bg', 'label-caps'])('declares the %s utility', (utility) => {
        expect(css).toContain(`@utility ${utility} {`);
    });

    it('builds the chamfers as 8-point polygons from the tokens', () => {
        for (const size of ['sm', 'md']) {
            const block = css.match(new RegExp(`@utility chamfer-${size} \\{([\\s\\S]*?)\\n\\}`))?.[1] ?? '';
            const points = block.match(/polygon\(([\s\S]*?)\);/)?.[1]?.split(/,(?![^(]*\))/) ?? [];

            expect(points).toHaveLength(8);
            expect(block).toContain(`var(--chamfer-${size})`);
        }
    });

    it('draws the abyss background as an SVG data URI with transform-only caustics', () => {
        const block = css.match(/@utility abyss-bg \{([\s\S]*?)\n\}/)?.[1] ?? '';
        const keyframes = css.match(/@keyframes abyss-caustics \{([\s\S]*?)\n {4}\}/)?.[1] ?? '';

        expect(block).toContain('data:image/svg+xml');
        expect(block).toMatch(/prefers-reduced-motion: reduce[\s\S]*animation: none/);
        expect(keyframes).toContain('transform');
        expect(keyframes).not.toMatch(/opacity|filter|background|top|left|width|height/);
    });

    it('draws a 2px cyan focus ring with 2px offset', () => {
        const block = css.match(/:focus-visible \{([\s\S]*?)\}/)?.[1] ?? '';

        expect(block).toContain('outline: 2px solid var(--color-cyan-300);');
        expect(block).toContain('outline-offset: 2px;');
    });

    it('drops the previous palette and font', () => {
        expect(css.toLowerCase()).not.toContain('#050a12');
        expect(css.toLowerCase()).not.toContain('#22d3ee');
        expect(css).not.toContain('Exo 2');
    });

    it.each([
        '--color-bg',
        '--color-bg-navy',
        '--color-panel',
        '--color-line',
        '--color-cyan',
        '--color-cyan-bright',
        '--color-blue',
        '--color-purple',
        '--color-amber',
        '--color-danger',
        '--color-success',
        '--color-text',
        '--color-muted',
    ])('keeps %s as a transitional alias of an Abissal token', (token) => {
        expect(tokenValue(token)).toMatch(/var\(--color-(abyss|ocean|cyan|biolum|amber|success|danger|text)-\d+\)/);
    });

    it.each(['hud-glow', 'hud-border', 'hud-glass', 'hud-grid', 'hud-label'])('keeps %s as a marked legacy alias', (utility) => {
        expect(css).toMatch(new RegExp(`/\\* legacy alias — removido em T23 \\*/\\s*@utility ${utility} \\{`));
    });

    it.each(['text-100', 'text-400', 'cyan-300', 'amber-400', 'success-400', 'danger-400'] as const)(
        'keeps %s at WCAG AA (4.5:1) over abyss-900',
        (name) => {
            expect(contrastRatio(COLORS[name], COLORS['abyss-900'])).toBeGreaterThanOrEqual(4.5);
        },
    );

    it('disables continuous animations for reduced motion', () => {
        expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\) \{\s*\*,[\s\S]*animation-duration: 0s/);
    });
});
