import { useMemo } from 'react';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { isAllowedImage } from './images';

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
 * Images only load from Modrinth's CDN and raw GitHub. Any other image is dropped,
 * or shown as a plain link to where it lives.
 */
DOMPurify.addHook('uponSanitizeElement', (node) => {
    if (node.nodeName !== 'IMG') return;
    const element = node as HTMLImageElement;
    const src = element.getAttribute('src') ?? '';
    if (isAllowedImage(src)) return;

    if (/^https?:\/\//i.test(src) && element.parentNode) {
        const link = element.ownerDocument.createElement('a');
        link.setAttribute('href', src);
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noreferrer noopener');
        link.textContent = element.getAttribute('alt') || 'Image';
        element.parentNode.replaceChild(link, element);
    } else {
        element.parentNode?.removeChild(element);
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
            FORBID_ATTR: ['style', 'class', 'id', 'name'],
        });
    }, [text]);

    return <div className={`mcc-markdown ${className ?? ''}`} dangerouslySetInnerHTML={{ __html: html }} />;
}
