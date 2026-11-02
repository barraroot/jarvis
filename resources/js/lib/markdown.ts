import DOMPurify from 'dompurify';
import MarkdownIt from 'markdown-it';

/**
 * Sanitized Markdown renderer (US-11.1): markdown-it never interprets raw
 * HTML (`html: false` — tags are escaped, not executed) and DOMPurify
 * re-sanitizes the result against a strict allowlist as defense in depth.
 * Anything outside the allowlist (scripts, event handlers, javascript:
 * URLs, styles) is stripped or rendered as plain text.
 */
const markdown = new MarkdownIt({
    html: false,
    linkify: true,
});

/**
 * The only tags the renderer may emit. `img` is intentionally absent:
 * remote images are never embedded, so no `onerror` payload can slip in.
 */
const ALLOWED_TAGS = [
    'p', 'br', 'strong', 'em', 's', 'code', 'pre',
    'ul', 'ol', 'li',
    'a',
    'h1', 'h2', 'h3', 'h4',
    'blockquote', 'hr',
    'table', 'thead', 'tbody', 'tr', 'th', 'td',
];

/**
 * External links always open in a new tab, unprivileged.
 */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.getAttribute('href') !== null) {
        node.setAttribute('rel', 'noopener noreferrer');
        node.setAttribute('target', '_blank');
    }
});

/**
 * Renders Markdown to sanitized HTML. Raw HTML in the source is escaped and
 * shown as text; dangerous URLs and attributes are removed by DOMPurify.
 */
export function renderMarkdown(source: string): string {
    const rendered = markdown.render(source);

    return DOMPurify.sanitize(rendered, {
        ALLOWED_TAGS,
        ALLOWED_ATTR: ['href', 'title'],
    });
}
