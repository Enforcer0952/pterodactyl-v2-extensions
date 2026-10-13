import { useMemo } from 'react';
import DOMPurify from 'dompurify';
import { marked } from 'marked';

/** Every link from project text opens in a new tab, never inside the panel. */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noreferrer noopener');
    }
    if (node.tagName === 'IMG') {
        node.setAttribute('loading', 'lazy');
        node.setAttribute('referrerpolicy', 'no-referrer');
    }
});

/**
 * Markdown (with the HTML Modrinth allows in it) rendered safely: scripts, event
 * handlers, frames, forms and styles are stripped, and links open in a new tab.
 */
export default function Markdown({ text, className }: { text: string; className?: string }) {
    const html = useMemo(() => {
        const raw = marked.parse(text, { async: false, gfm: true, breaks: false });
        return DOMPurify.sanitize(raw, {
            FORBID_TAGS: ['style', 'iframe', 'form', 'input', 'button', 'textarea', 'select', 'object', 'embed'],
            FORBID_ATTR: ['style'],
        });
    }, [text]);

    return <div className={`mcc-markdown ${className ?? ''}`} dangerouslySetInnerHTML={{ __html: html }} />;
}
