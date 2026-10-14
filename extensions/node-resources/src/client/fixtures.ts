import type { NodeReport, Resource } from './api';

const GB = 1024 ** 3;

const resource = (over: Partial<Resource> = {}): Resource => ({
    host_total: 16 * GB,
    host_used: 8 * GB,
    host_free: 8 * GB,
    node_size: 16 * GB,
    overallocate: 0,
    panel_limit: 16 * GB,
    allocated: 6 * GB,
    unlimited: 0,
    servers_used: 4 * GB,
    committed: 6 * GB,
    unallocated: 10 * GB,
    overcommitted: false,
    ...over,
});

export const report = (over: Partial<NodeReport> = {}): NodeReport => ({
    node: {
        id: 3,
        name: 'Node One',
        fqdn: 'n1.example.com',
        memory: 16384,
        memory_overallocate: 0,
        disk: 512000,
        disk_overallocate: 0,
        maintenance: false,
    },
    collected_at: '2026-10-14T10:00:00+00:00',
    reachable: true,
    probe: { state: 'ok', server: { id: 'abcd1234', name: 'Node probe' }, last_at: '2026-10-14T10:00:00+00:00' },
    ram: resource(),
    disk: resource({
        host_total: 500 * GB,
        host_used: 450 * GB,
        host_free: 50 * GB,
        node_size: 500 * GB,
        panel_limit: 500 * GB,
        allocated: 200 * GB,
        committed: 200 * GB,
        unallocated: 300 * GB,
    }),
    cpu: { threads: 8, capacity: 800, load1: 1.5, allocated: 200, unlimited: 0, servers_used: 35, committed: 200 },
    forecast: { days: 12, growth_per_day: 2 * GB },
    warnings: [{ id: 'disk-high', level: 'warning', text: 'The disk is 90% full. 50 GB left.' }],
    data_since: '2026-10-01T10:00:00+00:00',
    servers: [
        {
            id: 'aaaa1111',
            admin_id: 7,
            name: 'Survival',
            state: 'running',
            status: null,
            is_probe: false,
            memory: { limit: 0, now: 2 * GB, peak: 3 * GB },
            disk: { limit: 20 * GB, now: 10 * GB, peak: 10 * GB },
            cpu: { limit: 0, now: 12, peak: 80 },
            suggestion: { memory: 4 * GB, disk: null },
        },
    ],
    collector: { last_run_at: '2026-10-14T10:00:00+00:00', age: 30, stale: false, nodes_ok: 1, nodes_failed: 0, servers: 1, seconds: 2 },
    settings: { disk_percent: 85, forecast_days: 7, retention: '30d' },
    ...over,
});

export const history = {
    range: '24h' as const,
    step: 300,
    ranges: ['24h', '7d', '30d'] as ('24h' | '7d' | '30d')[],
    points: [0, 1, 2, 3].map((n) => ({
        t: Math.floor(Date.now() / 1000) - 3600 * (4 - n),
        mem: (4 + n) * 1024 ** 3,
        disk: 440 * 1024 ** 3,
        load: 1 + n / 10,
        sv_mem: 2 * 1024 ** 3,
        sv_disk: 100 * 1024 ** 3,
        sv_cpu: 10,
    })),
    mem_total: 16 * 1024 ** 3,
    disk_total: 500 * 1024 ** 3,
    cpus: 8,
};
