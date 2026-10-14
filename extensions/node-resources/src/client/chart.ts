/** Geometry for the line and area charts. Pure, so it is tested without a DOM. */

export interface Point {
    t: number;
    v: number | null;
}

/** Round a maximum up to a tidy number: 1, 2, 2.5, 5 or 10 times a power of ten. */
export function niceMax(max: number): number {
    if (!Number.isFinite(max) || max <= 0) return 1;
    const power = Math.pow(10, Math.floor(Math.log10(max)));
    for (const step of [1, 2, 2.5, 5, 10]) {
        if (max <= step * power) return step * power;
    }
    return 10 * power;
}

export interface Paths {
    line: string;
    area: string;
    /** x of every point, y of every point (null where there is no value), for hover. */
    xs: number[];
    ys: (number | null)[];
}

const fixed = (n: number) => Number(n.toFixed(2));

/**
 * Turn points into SVG path data on a width x height canvas. A missing value, or a hole
 * wider than `maxGap` seconds, breaks the line instead of being joined across.
 */
export function buildPaths(points: Point[], t0: number, t1: number, yMax: number, width: number, height: number, maxGap: number): Paths {
    const span = Math.max(1, t1 - t0);
    const top = yMax > 0 ? yMax : 1;
    const xs = points.map((point) => fixed(((point.t - t0) / span) * width));
    const ys = points.map((point) => (point.v === null ? null : fixed(height - Math.min(1, Math.max(0, point.v / top)) * height)));

    let line = '';
    let area = '';
    let run: number[] = [];

    const flush = () => {
        if (run.length > 0) {
            const first = run[0];
            const last = run[run.length - 1];
            line += run.map((i, n) => `${n === 0 ? 'M' : 'L'}${xs[i]} ${ys[i]}`).join(' ');
            area += `M${xs[first]} ${height} ${run.map((i) => `L${xs[i]} ${ys[i]}`).join(' ')} L${xs[last]} ${height} Z`;
            line += ' ';
            area += ' ';
        }
        run = [];
    };

    points.forEach((point, i) => {
        if (point.v === null) {
            flush();
            return;
        }
        if (run.length > 0 && point.t - points[run[run.length - 1]].t > maxGap) flush();
        run.push(i);
    });
    flush();

    return { line: line.trim(), area: area.trim(), xs, ys };
}

/** Index of the point closest to a position between 0 and 1 across the chart. */
export function nearestIndex(points: Point[], fraction: number, t0: number, t1: number): number {
    if (points.length === 0) return -1;
    const target = t0 + Math.min(1, Math.max(0, fraction)) * (t1 - t0);
    let best = 0;
    for (let i = 1; i < points.length; i++) {
        if (Math.abs(points[i].t - target) < Math.abs(points[best].t - target)) best = i;
    }
    return best;
}
