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

    it('strips class, id and name attributes', () => {
        const { container } = render(
            <Markdown text={'<p class="fixed inset-0" id="root" name="x">hi</p><a name="top" href="https://example.com">a</a>'} />,
        );

        const p = container.querySelector('p');
        expect(p?.getAttribute('class')).toBeNull();
        expect(p?.getAttribute('id')).toBeNull();
        expect(container.querySelector('a')?.getAttribute('name')).toBeNull();
    });

    it('only loads images from Modrinth and GitHub', () => {
        const { container } = render(
            <Markdown
                text={
                    '![ok](https://cdn.modrinth.com/a.png) ![gh](https://raw.githubusercontent.com/o/r/main/a.png) ' +
                    '![bad](https://evil.example/track.png) <img src="data:image/png;base64,AAAA">'
                }
            />,
        );

        const sources = [...container.querySelectorAll('img')].map((img) => img.getAttribute('src'));
        expect(sources).toEqual(['https://cdn.modrinth.com/a.png', 'https://raw.githubusercontent.com/o/r/main/a.png']);
        expect(container.querySelector('a[href="https://evil.example/track.png"]')?.textContent).toBe('bad');
    });
});
