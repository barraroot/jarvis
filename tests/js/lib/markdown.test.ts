import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SafeMarkdown from '@/components/ui/SafeMarkdown.vue';
import { renderMarkdown } from '@/lib/markdown';

describe('renderMarkdown', () => {
    it('renders inline script tags as escaped text', () => {
        const html = renderMarkdown('<script>alert(1)</script>');

        expect(html).not.toContain('<script>');
        expect(html).toContain('&lt;script&gt;');
    });

    it('removes javascript: URLs from links', () => {
        const html = renderMarkdown('[clique](javascript:alert(1))');

        expect(html).not.toContain('<a');
        expect(html).not.toContain('href="javascript:');
    });

    it('strips onerror handlers from markup the model emits', () => {
        const html = renderMarkdown('<img src=x onerror=alert(1)>');

        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img');
    });

    it('renders allowed markdown constructs', () => {
        const html = renderMarkdown('# Título\n\n**negrito** e `code`\n\n| A | B |\n|---|---|\n| 1 | 2 |');

        expect(html).toContain('<h1>');
        expect(html).toContain('<strong>');
        expect(html).toContain('<code>');
        expect(html).toContain('<table>');
    });

    it('external links open in a new unprivileged tab', () => {
        const html = renderMarkdown('[docs](https://example.com)');

        expect(html).toContain('target="_blank"');
        expect(html).toContain('rel="noopener noreferrer"');
    });

    it('keeps http and https links but never anchors unknown protocols', () => {
        const html = renderMarkdown('[a](http://example.com) [b](data:text/html,x)');

        expect(html).toContain('href="http://example.com"');
        expect(html).not.toContain('href="data:');
    });
});

describe('SafeMarkdown', () => {
    it('is the v-html sink and renders sanitized output only', () => {
        const wrapper = mount(SafeMarkdown, { props: { source: '<script>alert(1)</script> **ok**' } });

        expect(wrapper.get('[data-testid="safe-markdown"]').element.innerHTML).not.toContain('<script>');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.get('[data-testid="safe-markdown"]').element.innerHTML).toContain('<strong>ok</strong>');
    });
});
