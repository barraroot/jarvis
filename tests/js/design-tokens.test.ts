import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('HUD design tokens', () => {
    it.each([
        ['--color-bg', '#050a12'],
        ['--color-bg-navy', '#0a1628'],
        ['--color-panel', 'rgba(10, 22, 40, 0.6)'],
        ['--color-line', '#164e63'],
        ['--color-cyan', '#22d3ee'],
        ['--color-cyan-bright', '#67e8f9'],
        ['--color-blue', '#3b82f6'],
        ['--color-purple', '#a78bfa'],
        ['--color-amber', '#fbbf24'],
        ['--color-danger', '#f87171'],
        ['--color-text', '#cffafe'],
        ['--color-muted', '#64748b'],
    ])('defines %s as %s', (token, value) => {
        expect(css).toContain(`${token}: ${value};`);
    });

    it('defines mono and display fonts', () => {
        expect(css).toMatch(/--font-mono: 'JetBrains Mono'/);
        expect(css).toMatch(/--font-display: 'Orbitron'/);
    });

    it.each(['hud-glow', 'hud-border', 'hud-glass', 'hud-grid'])('declares the %s utility', (utility) => {
        expect(css).toContain(`@utility ${utility} {`);
    });

    it('disables continuous animations for reduced motion', () => {
        expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*animation-duration: 0s/);
    });
});
