<?php

namespace CrashGuard\Http\Controllers;

use Carbon\CarbonImmutable;
use CrashGuard\Services\Guard;
use CrashGuard\Support\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Every guarded server on the panel, for administrators.
 */
class AdminController extends Controller
{
    private const int PER_PAGE = 50;

    public function __construct(private readonly Guard $guard) {}

    public function servers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', 'in:all,tripped'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $defaults = $this->guard->defaults();
        $page = (int) ($data['page'] ?? 1);

        $base = DB::table('servers as sv')
            ->leftJoin(Guard::SERVERS.' as g', 'g.server_id', '=', 'sv.id')
            ->join('nodes as n', 'n.id', '=', 'sv.node_id')
            ->whereNull('sv.status')
            ->whereRaw('COALESCE(g.enabled, ?) = 1', [$defaults['enabled'] ? 1 : 0]);

        $guarded = (clone $base)->count();
        $tripped = (clone $base)->whereNotNull('g.tripped_at')->count();

        if (($data['status'] ?? 'all') === 'tripped') {
            $base->whereNotNull('g.tripped_at');
        }
        $search = trim((string) ($data['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $base->where(fn ($query) => $query->where('sv.name', 'like', $like)->orWhere('sv.uuidShort', 'like', $like));
        }

        $total = (clone $base)->count();
        $rows = $base
            ->orderByRaw('g.tripped_at IS NULL')
            ->orderBy('sv.name')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['sv.id', 'sv.name', 'sv.uuidShort', 'n.name as node', 'g.max_crashes', 'g.window_minutes', 'g.last_state', 'g.last_sampled_at', 'g.tripped_at', 'g.trip_crashes', 'g.trip_minutes']);

        $ids = $rows->pluck('id')->all();
        $since = CarbonImmutable::now()->subDay();
        $counts = $ids === [] ? collect() : DB::table(Guard::EVENTS)
            ->whereIn('server_id', $ids)->where('kind', 'crash')->where('status', 'confirmed')->where('occurred_at', '>=', $since)
            ->selectRaw('server_id, COUNT(*) as total, MAX(occurred_at) as last_at')->groupBy('server_id')->get()->keyBy('server_id');
        $recent = [];
        if ($ids !== []) {
            foreach (DB::table(Guard::EVENTS)->whereIn('server_id', $ids)->whereIn('kind', ['trip', 'blocked', 'release', 'cleared'])->where('occurred_at', '>=', $since->subDays(6))
                ->orderByDesc('occurred_at')->get(['server_id', 'kind', 'note', 'occurred_at']) as $event) {
                if (count($recent[$event->server_id] ?? []) < 3) {
                    $recent[$event->server_id][] = [
                        'kind' => $event->kind,
                        'note' => $event->note,
                        'occurred_at' => CarbonImmutable::parse($event->occurred_at)->toAtomString(),
                    ];
                }
            }
        }

        return new JsonResponse([
            'data' => $rows->map(fn (object $row): array => [
                'id' => $row->uuidShort,
                'name' => $row->name,
                'node' => $row->node,
                'status' => $row->tripped_at !== null ? 'tripped' : 'watching',
                'max_crashes' => $row->max_crashes === null ? $defaults['max_crashes'] : Limits::crashes($row->max_crashes),
                'window_minutes' => $row->window_minutes === null ? $defaults['window_minutes'] : Limits::minutes($row->window_minutes),
                'last_state' => $row->last_state,
                'last_seen_at' => $row->last_sampled_at === null ? null : CarbonImmutable::parse($row->last_sampled_at)->toAtomString(),
                'tripped_at' => $row->tripped_at === null ? null : CarbonImmutable::parse($row->tripped_at)->toAtomString(),
                'trip_crashes' => $row->trip_crashes === null ? null : (int) $row->trip_crashes,
                'trip_minutes' => $row->trip_minutes === null ? null : (int) $row->trip_minutes,
                'crashes_24h' => (int) ($counts->get($row->id)->total ?? 0),
                'last_crash_at' => ($counts->get($row->id)->last_at ?? null) === null ? null : CarbonImmutable::parse($counts->get($row->id)->last_at)->toAtomString(),
                'recent' => $recent[$row->id] ?? [],
            ])->all(),
            'meta' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'guarded' => $guarded,
                'tripped' => $tripped,
                'default_enabled' => $defaults['enabled'],
            ],
        ]);
    }
}
