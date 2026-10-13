import { useEffect, useRef, useState } from 'react';
import { User } from 'lucide-react';

/**
 * A rotatable 3D model of the skin. skinview3d (with three.js) is loaded only when
 * the first viewer opens, so the console page itself stays light.
 */
export default function SkinViewer({ url, width = 180, height = 260 }: { url: string | null; width?: number; height?: number }) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!url || !canvas.current) return;
        let disposed = false;
        let viewer: { dispose(): void } | null = null;
        setFailed(false);

        import('skinview3d')
            .then(async ({ SkinViewer: Viewer, WalkingAnimation }) => {
                if (disposed || !canvas.current) return;
                const instance = new Viewer({ canvas: canvas.current, width, height });
                viewer = instance;
                instance.zoom = 0.85;
                instance.fov = 50;
                instance.autoRotate = false;
                instance.animation = new WalkingAnimation();
                instance.animation.speed = 0.5;
                if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
                    instance.animation.paused = true;
                }
                await instance.loadSkin(url);
            })
            .catch(() => {
                if (!disposed) setFailed(true);
            });

        return () => {
            disposed = true;
            viewer?.dispose();
        };
    }, [url, width, height]);

    if (!url || failed) {
        return (
            <div
                style={{ width, height }}
                className={
                    'mcp:flex mcp:shrink-0 mcp:items-center mcp:justify-center mcp:rounded-md mcp:bg-muted mcp:text-muted-foreground'
                }
            >
                <User className={'mcp:size-12'} aria-hidden />
            </div>
        );
    }

    return (
        <canvas
            ref={canvas}
            width={width}
            height={height}
            className={'mcp:shrink-0 mcp:cursor-grab mcp:rounded-md mcp:bg-muted mcp:active:cursor-grabbing'}
            aria-label={'3D player skin. Drag to rotate.'}
        />
    );
}
