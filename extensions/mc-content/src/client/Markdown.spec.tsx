import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import Markdown from './Markdown';

describe('Markdown', () => {
    it('renders Modrinth descriptions without scripts, handlers or frames', () => {
        const { container } = render(
            <Markdown
                text={
                    '# Title\n\n<script>alert(1)</script><img src="https://cdn.modrinth.com/a.png" onerror="alert(2)">' +
                    '<iframe src="https://evil.example"></iframe>\n\n[site](https://example.com) <a href="javascript:alert(3)">x</a>'
                }
            />,
        );

        expect(container.querySelector('h1')?.textContent).toBe('Title');
        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('iframe')).toBeNull();
        expect(container.querySelector('img')?.getAttribute('onerror')).toBeNull();

        const links = [...container.querySelectorAll('a')];
        expect(links[0].getAttribute('target')).toBe('_blank');
        expect(links[0].getAttribute('rel')).toBe('noreferrer noopener');
        expect(links.some((link) => (link.getAttribute('href') ?? '').startsWith('javascript:'))).toBe(false);
    });
});
