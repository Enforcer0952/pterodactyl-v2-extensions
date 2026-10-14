import { expect, it } from 'vitest';
import { buildPaths, nearestIndex, niceMax, type Point } from './chart';

it('rounds a maximum up to a tidy number', () => {
    expect(niceMax(0)).toBe(1);
    expect(niceMax(Number.NaN)).toBe(1);
    expect(niceMax(0.7)).toBe(1);
    expect(niceMax(1.1)).toBe(2);
    expect(niceMax(2.3)).toBe(2.5);
    expect(niceMax(4.2)).toBe(5);
    expect(niceMax(73)).toBe(100);
    expect(niceMax(1_000)).toBe(1_000);
    expect(niceMax(1_001)).toBe(2_000);
});

it('draws a line across the canvas', () => {
    const points: Point[] = [
        { t: 0, v: 0 },
        { t: 50, v: 50 },
        { t: 100, v: 100 },
    ];
    const paths = buildPaths(points, 0, 100, 100, 200, 100, 1000);

    expect(paths.line).toBe('M0 100 L100 50 L200 0');
    expect(paths.area).toBe('M0 100 L0 100 L100 50 L200 0 L200 100 Z');
    expect(paths.xs).toEqual([0, 100, 200]);
    expect(paths.ys).toEqual([100, 50, 0]);
});

it('breaks the line at missing values', () => {
    const points: Point[] = [
        { t: 0, v: 10 },
        { t: 10, v: null },
        { t: 20, v: 10 },
        { t: 30, v: 10 },
    ];
    const paths = buildPaths(points, 0, 30, 10, 30, 10, 1000);

    expect(paths.line.match(/M/g)).toHaveLength(2);
    expect(paths.ys[1]).toBeNull();
});

it('breaks the line at long holes in time', () => {
    const points: Point[] = [
        { t: 0, v: 1 },
        { t: 300, v: 1 },
        { t: 5000, v: 1 },
        { t: 5300, v: 1 },
    ];
    const paths = buildPaths(points, 0, 5300, 1, 100, 10, 900);

    expect(paths.line.match(/M/g)).toHaveLength(2);
});

it('keeps values inside the canvas and handles no data', () => {
    const paths = buildPaths(
        [
            { t: 0, v: 500 },
            { t: 10, v: -5 },
        ],
        0,
        10,
        100,
        100,
        50,
        100,
    );
    expect(paths.ys).toEqual([0, 50]);

    const none = buildPaths([], 0, 10, 100, 100, 50, 100);
    expect(none.line).toBe('');
    expect(none.area).toBe('');
});

it('finds the point under the mouse', () => {
    const points: Point[] = [0, 100, 200, 300].map((t) => ({ t, v: 1 }));
    expect(nearestIndex(points, 0, 0, 300)).toBe(0);
    expect(nearestIndex(points, 0.5, 0, 300)).toBe(1);
    expect(nearestIndex(points, 0.8, 0, 300)).toBe(2);
    expect(nearestIndex(points, 2, 0, 300)).toBe(3);
    expect(nearestIndex([], 0.5, 0, 300)).toBe(-1);
});
