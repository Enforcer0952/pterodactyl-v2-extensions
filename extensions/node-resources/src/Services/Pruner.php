<?php

namespace NodeResources\Services;

use Illuminate\Support\Facades\DB;
use NodeResources\Support\Retention;
use NodeResources\Support\Rollup;
use NodeResources\Support\Tables;

/** Nightly clean-up: raw samples after 48 hours, rollups after the retention in the settings. */
class Pruner
{
    public function __construct(private readonly Meta $meta) {}

    /** @return array<string, int> rows deleted per table */
    public function prune(?int $now = null): array
    {
        $now ??= time();
        $retention = Retention::from(app('ext.node-resources.settings')->get('retention', Retention::DEFAULT));
        $cutoffs = $retention->cutoffs($now);

        $deleted = [
            Tables::NODE_SAMPLES => $this->delete(Tables::NODE_SAMPLES, 'taken_at', $cutoffs['raw']),
            Tables::SERVER_SAMPLES => $this->delete(Tables::SERVER_SAMPLES, 'taken_at', $cutoffs['raw']),
        ];
        foreach ([Tables::NODE_ROLLUPS, Tables::SERVER_ROLLUPS] as $table) {
            $deleted[$table] = $this->delete($table, 'bucket', $cutoffs['five_minutes'], Rollup::FIVE_MINUTES)
                + $this->delete($table, 'bucket', $cutoffs['hourly'], Rollup::HOUR);
        }

        $this->meta->put('pruned', ['at' => $now, 'deleted' => $deleted]);

        return $deleted;
    }

    /** Deletes in slices so a big table does not hold the database for long. */
    private function delete(string $table, string $column, int $before, ?int $resolution = null): int
    {
        $total = 0;
        do {
            $query = DB::table($table)->where($column, '<', $before);
            if ($resolution !== null) {
                $query->where('resolution', $resolution);
            }
            $count = $query->limit(5000)->delete();
            $total += $count;
        } while ($count >= 5000);

        return $total;
    }
}
